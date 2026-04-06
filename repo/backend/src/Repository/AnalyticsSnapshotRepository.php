<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnalyticsSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsSnapshot>
 */
class AnalyticsSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsSnapshot::class);
    }

    /** @param list<string> $orgUnits @return list<AnalyticsSnapshot> */
    public function findByDateRangeAndOrgUnits(\DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc, array $orgUnits): array
    {
        $qb = $this->createQueryBuilder('snapshot')
            ->where('snapshot.occurredAtUtc >= :from')
            ->andWhere('snapshot.occurredAtUtc <= :to')
            ->setParameter('from', $fromUtc)
            ->setParameter('to', $toUtc)
            ->orderBy('snapshot.occurredAtUtc', 'ASC');

        if ($orgUnits !== []) {
            $qb->andWhere('snapshot.orgUnit IN (:orgUnits)')->setParameter('orgUnits', $orgUnits);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<string> */
    public function distinctOrgUnits(): array
    {
        $rows = $this->createQueryBuilder('snapshot')
            ->select('DISTINCT snapshot.orgUnit AS orgUnit')
            ->orderBy('snapshot.orgUnit', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $units = [];
        foreach ($rows as $row) {
            $orgUnit = (string) ($row['orgUnit'] ?? '');
            if ($orgUnit !== '') {
                $units[] = $orgUnit;
            }
        }

        return $units;
    }
}
