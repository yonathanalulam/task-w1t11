<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

final class GovernanceLogRetentionService
{
    public const MINIMUM_RETENTION_YEARS = 7;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{minimumRetentionYears: int, purgeEligibleBeforeUtc: string}
     */
    public function policyMetadata(): array
    {
        $cutoff = $this->retentionCutoffUtc();

        return [
            'minimumRetentionYears' => self::MINIMUM_RETENTION_YEARS,
            'purgeEligibleBeforeUtc' => $cutoff->format(DATE_ATOM),
        ];
    }

    /**
     * @return array{auditEligible: int, sensitiveEligible: int, cutoffUtc: string}
     */
    public function eligibleCounts(): array
    {
        $connection = $this->entityManager->getConnection();
        $cutoff = $this->retentionCutoffUtc();
        $cutoffSql = $cutoff->format('Y-m-d H:i:s');

        $auditEligible = (int) $connection->fetchOne(
            'SELECT COUNT(id) FROM audit_logs WHERE created_at_utc < :cutoff',
            ['cutoff' => $cutoffSql],
        );

        $sensitiveEligible = (int) $connection->fetchOne(
            'SELECT COUNT(id) FROM sensitive_access_logs WHERE created_at_utc < :cutoff',
            ['cutoff' => $cutoffSql],
        );

        return [
            'auditEligible' => $auditEligible,
            'sensitiveEligible' => $sensitiveEligible,
            'cutoffUtc' => $cutoff->format(DATE_ATOM),
        ];
    }

    /**
     * @return array{auditDeleted: int, sensitiveDeleted: int, cutoffUtc: string}
     */
    public function purgeExpired(int $batchSize = 5000): array
    {
        $batchSize = max(100, min(20000, $batchSize));
        $connection = $this->entityManager->getConnection();

        $cutoff = $this->retentionCutoffUtc();
        $cutoffSql = $cutoff->format('Y-m-d H:i:s');

        $auditDeleted = $this->purgeTable(
            sprintf('DELETE FROM audit_logs WHERE created_at_utc < :cutoff LIMIT %d', $batchSize),
            $cutoffSql,
        );

        $sensitiveDeleted = $this->purgeTable(
            sprintf('DELETE FROM sensitive_access_logs WHERE created_at_utc < :cutoff LIMIT %d', $batchSize),
            $cutoffSql,
        );

        return [
            'auditDeleted' => $auditDeleted,
            'sensitiveDeleted' => $sensitiveDeleted,
            'cutoffUtc' => $cutoff->format(DATE_ATOM),
        ];
    }

    private function retentionCutoffUtc(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('-%d years', self::MINIMUM_RETENTION_YEARS));
    }

    private function purgeTable(string $deleteSql, string $cutoffSql): int
    {
        $deleted = 0;

        while (true) {
            $affected = (int) $this->entityManager->getConnection()->executeStatement($deleteSql, ['cutoff' => $cutoffSql]);
            $deleted += $affected;

            if ($affected === 0) {
                break;
            }
        }

        return $deleted;
    }
}
