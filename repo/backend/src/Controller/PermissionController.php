<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\ApiResponse;
use App\Security\AuthSessionService;
use App\Security\PermissionRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/permissions')]
final class PermissionController extends AbstractController
{
    public function __construct(
        private readonly AuthSessionService $authSession,
        private readonly PermissionRegistry $permissionRegistry,
    ) {
    }

    #[Route('/me', name: 'api_permissions_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        if (!$user instanceof User) {
            return ApiResponse::error('UNAUTHENTICATED', 'Authentication required.', JsonResponse::HTTP_UNAUTHORIZED);
        }

        $roles = $user->getRoles();
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'username' => $user->getUsername(),
            'roles' => $roles,
            'permissions' => $this->permissionRegistry->resolvePermissions($roles),
            'navigation' => $this->permissionRegistry->resolveNavigation($roles),
        ], requestId: is_string($requestId) ? $requestId : null);
    }
}
