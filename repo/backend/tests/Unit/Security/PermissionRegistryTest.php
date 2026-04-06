<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\PermissionRegistry;
use App\Security\Role;
use PHPUnit\Framework\TestCase;

final class PermissionRegistryTest extends TestCase
{
    public function testSystemAdminIncludesSchedulingAuthority(): void
    {
        $registry = new PermissionRegistry();
        $permissions = $registry->resolvePermissions([Role::SYSTEM_ADMIN]);

        self::assertContains('scheduling.admin', $permissions);
        self::assertContains('credential.review', $permissions);
        self::assertContains('nav.scheduling', $permissions);
        self::assertContains('analytics.feature.manage', $permissions);
        self::assertContains('nav.analytics', $permissions);
        self::assertContains('admin.passwordReset', $permissions);
        self::assertContains('admin.rollback', $permissions);
        self::assertContains('admin.audit.read', $permissions);
        self::assertContains('admin.anomaly.manage', $permissions);
    }

    public function testContentAdminIncludesQuestionBankPermissions(): void
    {
        $registry = new PermissionRegistry();
        $permissions = $registry->resolvePermissions([Role::CONTENT_ADMIN]);

        self::assertContains('nav.questionBank', $permissions);
        self::assertContains('question.manage', $permissions);
        self::assertContains('question.publish', $permissions);
        self::assertContains('question.importExport', $permissions);
    }

    public function testAnalystIncludesAnalyticsWorkbenchPermissions(): void
    {
        $registry = new PermissionRegistry();
        $permissions = $registry->resolvePermissions([Role::ANALYST]);

        self::assertContains('nav.analytics', $permissions);
        self::assertContains('analytics.query', $permissions);
        self::assertContains('analytics.export', $permissions);
        self::assertContains('analytics.feature.manage', $permissions);
    }
}
