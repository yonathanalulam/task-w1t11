<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GovernanceLogRetentionCommandTest extends KernelTestCase
{
    public function testRetentionCommandPurgesOnlyRowsOlderThanSevenYears(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $suffix = bin2hex(random_bytes(4));
        $oldTime = (new \DateTimeImmutable('-8 years', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $recentTime = (new \DateTimeImmutable('-6 years', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $oldAction = sprintf('retention.test.audit.old.%s', $suffix);
        $recentAction = sprintf('retention.test.audit.recent.%s', $suffix);

        $connection->executeStatement(
            'INSERT INTO audit_logs (actor_username, action_type, payload, created_at_utc) VALUES (:actor, :action, :payload, :createdAt)',
            [
                'actor' => 'retention_test',
                'action' => $oldAction,
                'payload' => json_encode(['sample' => 'old'], JSON_THROW_ON_ERROR),
                'createdAt' => $oldTime,
            ],
        );
        $connection->executeStatement(
            'INSERT INTO audit_logs (actor_username, action_type, payload, created_at_utc) VALUES (:actor, :action, :payload, :createdAt)',
            [
                'actor' => 'retention_test',
                'action' => $recentAction,
                'payload' => json_encode(['sample' => 'recent'], JSON_THROW_ON_ERROR),
                'createdAt' => $recentTime,
            ],
        );

        $oldSensitiveEntityId = sprintf('old-%s', $suffix);
        $recentSensitiveEntityId = sprintf('recent-%s', $suffix);

        $connection->executeStatement(
            'INSERT INTO sensitive_access_logs (actor_username, entity_type, entity_id, field_name, reason, created_at_utc) VALUES (:actor, :entityType, :entityId, :fieldName, :reason, :createdAt)',
            [
                'actor' => 'retention_test',
                'entityType' => 'retention_test',
                'entityId' => $oldSensitiveEntityId,
                'fieldName' => 'license_number',
                'reason' => 'Old retention fixture.',
                'createdAt' => $oldTime,
            ],
        );
        $connection->executeStatement(
            'INSERT INTO sensitive_access_logs (actor_username, entity_type, entity_id, field_name, reason, created_at_utc) VALUES (:actor, :entityType, :entityId, :fieldName, :reason, :createdAt)',
            [
                'actor' => 'retention_test',
                'entityType' => 'retention_test',
                'entityId' => $recentSensitiveEntityId,
                'fieldName' => 'license_number',
                'reason' => 'Recent retention fixture.',
                'createdAt' => $recentTime,
            ],
        );

        $application = new Application(self::$kernel);
        $command = $application->find('app:governance:retention-enforce');

        $dryRunTester = new CommandTester($command);
        $dryRunTester->execute(['--dry-run' => true]);
        $dryRunOutput = $dryRunTester->getDisplay();
        self::assertStringContainsString('Retention policy: keep at least 7 years.', $dryRunOutput);
        self::assertStringContainsString('Eligible now - audit_logs: 1, sensitive_access_logs: 1', $dryRunOutput);

        $purgeTester = new CommandTester($command);
        $purgeTester->execute([]);
        $purgeOutput = $purgeTester->getDisplay();
        self::assertStringContainsString('Purged expired rows - audit_logs: 1, sensitive_access_logs: 1', $purgeOutput);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(id) FROM audit_logs WHERE action_type = ?', [$oldAction]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(id) FROM audit_logs WHERE action_type = ?', [$recentAction]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(id) FROM sensitive_access_logs WHERE entity_id = ?', [$oldSensitiveEntityId]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(id) FROM sensitive_access_logs WHERE entity_id = ?', [$recentSensitiveEntityId]));
    }
}
