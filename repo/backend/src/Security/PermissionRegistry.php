<?php

declare(strict_types=1);

namespace App\Security;

final class PermissionRegistry
{
    /**
     * @var array<string, list<string>>
     */
    private array $permissionsByRole = [
        Role::STANDARD_USER => [
            'nav.dashboard',
            'nav.practitioner',
            'nav.scheduling',
            'practitioner.manage.self',
            'credential.upload.self',
            'appointment.book.self',
        ],
        Role::CONTENT_ADMIN => [
            'nav.dashboard',
            'nav.questionBank',
            'question.manage',
            'question.importExport',
            'question.publish',
        ],
        Role::CREDENTIAL_REVIEWER => [
            'nav.dashboard',
            'nav.credentialReview',
            'credential.review',
        ],
        Role::ANALYST => [
            'nav.dashboard',
            'nav.analytics',
            'analytics.query',
            'analytics.export',
            'analytics.feature.manage',
        ],
        Role::SYSTEM_ADMIN => [
            'nav.dashboard',
            'nav.admin',
            'nav.credentialReview',
            'nav.scheduling',
            'nav.analytics',
            'admin.passwordReset',
            'admin.rollback',
            'admin.audit.read',
            'admin.anomaly.manage',
            'scheduling.admin',
            'auth.override.cancel24h',
            'credential.review',
            'analytics.query',
            'analytics.export',
            'analytics.feature.manage',
        ],
    ];

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    public function resolvePermissions(array $roles): array
    {
        $merged = [];
        foreach ($roles as $role) {
            foreach ($this->permissionsByRole[$role] ?? [] as $permission) {
                $merged[$permission] = true;
            }
        }

        return array_keys($merged);
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    public function resolveNavigation(array $roles): array
    {
        $navItems = [];
        foreach ($this->resolvePermissions($roles) as $permission) {
            if (str_starts_with($permission, 'nav.')) {
                $navItems[] = substr($permission, 4);
            }
        }

        sort($navItems);

        return array_values(array_unique($navItems));
    }

    /** @return array<string, list<string>> */
    public function definition(): array
    {
        return $this->permissionsByRole;
    }
}
