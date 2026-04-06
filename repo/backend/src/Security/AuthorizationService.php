<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AuthorizationService
{
    public function __construct(private readonly PermissionRegistry $permissionRegistry)
    {
    }

    /** @param list<string> $requiredRoles */
    public function assertAnyRole(?User $user, array $requiredRoles): void
    {
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        foreach ($requiredRoles as $role) {
            if (in_array($role, $user->getRoles(), true)) {
                return;
            }
        }

        throw new AccessDeniedHttpException('Insufficient permissions.');
    }

    public function assertPermission(?User $user, string $requiredPermission): void
    {
        if (!$this->hasPermission($user, $requiredPermission)) {
            if (!$user instanceof User) {
                throw new AccessDeniedHttpException('Authentication required.');
            }

            throw new AccessDeniedHttpException('Insufficient permissions.');
        }
    }

    public function hasPermission(?User $user, string $requiredPermission): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        $permissions = $this->permissionRegistry->resolvePermissions($user->getRoles());

        return in_array($requiredPermission, $permissions, true);
    }
}
