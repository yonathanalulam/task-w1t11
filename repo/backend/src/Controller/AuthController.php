<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\ApiValidationException;
use App\Http\ApiResponse;
use App\Http\JsonBodyParser;
use App\Repository\UserRepository;
use App\Security\AuthSessionService;
use App\Security\LocalCaptchaService;
use App\Security\PermissionRegistry;
use App\Security\Role;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/auth')]
final class AuthController extends AbstractController
{
    public function __construct(
        private readonly JsonBodyParser $jsonBodyParser,
        private readonly ValidatorInterface $validator,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSessionService $authSession,
        private readonly PermissionRegistry $permissionRegistry,
        private readonly AuditLogger $auditLogger,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly LocalCaptchaService $captchaService,
    ) {
    }

    #[Route('/csrf-token', name: 'api_auth_csrf', methods: ['GET'])]
    public function csrfToken(Request $request): JsonResponse
    {
        $token = $this->csrfTokenManager->getToken('api')->getValue();
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'csrfToken' => $token,
            'headerName' => 'X-CSRF-Token',
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/captcha', name: 'api_auth_captcha', methods: ['GET'])]
    public function captcha(Request $request): JsonResponse
    {
        $challenge = $this->captchaService->issueChallenge();
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success($challenge, requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $payload = $this->jsonBodyParser->parse($request);
        $this->validateRegisterPayload($payload);

        $username = trim((string) $payload['username']);
        $password = (string) $payload['password'];

        if ($this->users->findOneByUsername($username) instanceof User) {
            throw new ApiValidationException('Username is already in use.', [
                ['field' => 'username', 'issue' => 'already_taken'],
            ]);
        }

        $user = new User($username);
        $user->setRoles([Role::STANDARD_USER]);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->auditLogger->log('auth.registered', $username, ['roles' => $user->getRoles()]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'username' => $user->getUsername(),
            'roles' => $user->getRoles(),
        ], JsonResponse::HTTP_CREATED, is_string($requestId) ? $requestId : null);
    }

    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = $this->jsonBodyParser->parse($request);
        $this->validateLoginPayload($payload);

        $username = trim((string) $payload['username']);
        $password = (string) $payload['password'];

        $user = $this->users->findOneByUsername($username);
        if (!$user instanceof User) {
            $this->auditLogger->log('auth.login_failed', $username, ['reason' => 'unknown_user']);
            return ApiResponse::error('INVALID_CREDENTIALS', 'Invalid username or password.', JsonResponse::HTTP_UNAUTHORIZED);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($user->getLockedUntil() instanceof \DateTimeImmutable && $user->getLockedUntil() > $now) {
            $this->auditLogger->log('auth.locked', $username, ['lockedUntil' => $user->getLockedUntil()->format(DATE_ATOM)]);

            return ApiResponse::error(
                'ACCOUNT_LOCKED',
                'Account is temporarily locked.',
                JsonResponse::HTTP_LOCKED,
                [['lockedUntil' => $user->getLockedUntil()->format(DATE_ATOM)]],
            );
        }

        if ($user->getFailedAttemptCount() >= 3) {
            $captchaChallengeId = isset($payload['captchaChallengeId']) ? (string) $payload['captchaChallengeId'] : null;
            $captchaResponse = isset($payload['captchaResponse']) ? (string) $payload['captchaResponse'] : null;

            if (!$this->captchaService->validate($captchaChallengeId, $captchaResponse)) {
                throw new ApiValidationException('Captcha challenge is required.', [
                    ['field' => 'captchaResponse', 'issue' => 'required_or_invalid'],
                ]);
            }
        }

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            $user->incrementFailedAttempts();
            if ($user->getFailedAttemptCount() >= 5) {
                $user->setLockedUntil($now->modify('+15 minutes'));
            }
            $this->entityManager->flush();

            $this->auditLogger->log('auth.login_failed', $username, ['reason' => 'invalid_password']);
            if ($user->getLockedUntil() instanceof \DateTimeImmutable && $user->getLockedUntil() > $now) {
                return ApiResponse::error(
                    'ACCOUNT_LOCKED',
                    'Account is temporarily locked.',
                    JsonResponse::HTTP_LOCKED,
                    [['lockedUntil' => $user->getLockedUntil()->format(DATE_ATOM)]],
                );
            }

            return ApiResponse::error('INVALID_CREDENTIALS', 'Invalid username or password.', JsonResponse::HTTP_UNAUTHORIZED);
        }

        $user->clearLockoutState();
        $this->entityManager->flush();
        $this->authSession->login($user);
        $this->auditLogger->log('auth.login_success', $username);

        $permissions = $this->permissionRegistry->resolvePermissions($user->getRoles());
        $navigation = $this->permissionRegistry->resolveNavigation($user->getRoles());

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'user' => [
                'username' => $user->getUsername(),
                'roles' => $user->getRoles(),
            ],
            'permissions' => $permissions,
            'navigation' => $navigation,
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        if ($user instanceof User) {
            $this->auditLogger->log('auth.logout', $user->getUsername());
        }

        $this->authSession->logout();

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success(['status' => 'logged_out'], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        if (!$user instanceof User) {
            return ApiResponse::error('UNAUTHENTICATED', 'Authentication required.', JsonResponse::HTTP_UNAUTHORIZED);
        }

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'username' => $user->getUsername(),
            'roles' => $user->getRoles(),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    /** @param array<string, mixed> $payload */
    private function validateRegisterPayload(array $payload): void
    {
        $violations = $this->validator->validate($payload, new Assert\Collection(
            allowExtraFields: true,
            allowMissingFields: false,
            fields: [
                'username' => [new Assert\NotBlank(), new Assert\Length(min: 3, max: 180)],
                'password' => [new Assert\NotBlank(), new Assert\Length(min: 12, max: 255)],
            ],
        ));

        if (count($violations) > 0) {
            $details = [];
            foreach ($violations as $violation) {
                $details[] = [
                    'field' => trim((string) $violation->getPropertyPath(), '[]'),
                    'issue' => $violation->getMessage(),
                ];
            }

            throw new ApiValidationException('Registration payload is invalid.', $details);
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateLoginPayload(array $payload): void
    {
        $violations = $this->validator->validate($payload, new Assert\Collection(
            allowExtraFields: true,
            allowMissingFields: false,
            fields: [
                'username' => [new Assert\NotBlank(), new Assert\Length(min: 3, max: 180)],
                'password' => [new Assert\NotBlank(), new Assert\Length(min: 1, max: 255)],
            ],
        ));

        if (count($violations) > 0) {
            $details = [];
            foreach ($violations as $violation) {
                $details[] = [
                    'field' => trim((string) $violation->getPropertyPath(), '[]'),
                    'issue' => $violation->getMessage(),
                ];
            }

            throw new ApiValidationException('Login payload is invalid.', $details);
        }
    }
}
