<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ApiResponse;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class ApiSessionAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ApiRouteAccessPolicy $routeAccessPolicy,
        private readonly UserRepository $users,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $path = $request->getPathInfo();
        if (!$this->routeAccessPolicy->isApiPath($path)) {
            return false;
        }

        if ($request->getMethod() === Request::METHOD_OPTIONS) {
            return false;
        }

        return !$this->routeAccessPolicy->isPublicPath($path);
    }

    public function authenticate(Request $request): Passport
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $id = $session?->get(AuthSessionService::SESSION_USER_ID_KEY);

        if (!is_int($id) && !ctype_digit((string) $id)) {
            throw new CustomUserMessageAuthenticationException('Authentication required.');
        }

        return new SelfValidatingPassport(
            new UserBadge((string) $id, function (string $identifier) {
                $user = $this->users->find((int) $identifier);
                if ($user === null) {
                    throw new CustomUserMessageAuthenticationException('Authentication required.');
                }

                return $user;
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::error(
            'UNAUTHENTICATED',
            'Authentication required.',
            Response::HTTP_UNAUTHORIZED,
            [],
            is_string($requestId) ? $requestId : null,
        );
    }
}
