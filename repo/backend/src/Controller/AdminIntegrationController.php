<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Security\AuthSessionService;
use App\Security\AuthorizationService;
use App\Security\Role;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/integrations')]
final class AdminIntegrationController extends AbstractController
{
    public function __construct(
        private readonly AuthSessionService $authSession,
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/human-verification', name: 'api_admin_human_verification_get', methods: ['GET'])]
    public function humanVerificationStatus(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->authorization->assertAnyRole($user, [Role::SYSTEM_ADMIN]);

        $this->auditLogger->log('admin.human_verification.status_read', $this->username($user));

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'status' => 'DISABLED',
            'networkDependencyRequired' => false,
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    private function username(?User $user): ?string
    {
        return $user instanceof User ? $user->getUsername() : null;
    }
}
