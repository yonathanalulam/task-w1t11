<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminAnomalyAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminAnomalyAlert>
 */
class AdminAnomalyAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAnomalyAlert::class);
    }

    /** @return list<AdminAnomalyAlert> */
    public function listRecent(?string $status, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $qb = $this->createQueryBuilder('alert')
            ->orderBy('alert.lastDetectedAtUtc', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null && $status !== '') {
            $qb->andWhere('alert.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByTypeAndScopeKey(string $type, string $scopeKey): ?AdminAnomalyAlert
    {
        return $this->findOneBy([
            'alertType' => $type,
            'scopeKey' => $scopeKey,
        ]);
    }

    /** @return list<AdminAnomalyAlert> */
    public function findActiveByType(string $type): array
    {
        return $this->createQueryBuilder('alert')
            ->where('alert.alertType = :type')
            ->andWhere('alert.status IN (:statuses)')
            ->setParameter('type', $type)
            ->setParameter('statuses', [AdminAnomalyAlert::STATUS_OPEN, AdminAnomalyAlert::STATUS_ACKNOWLEDGED])
            ->getQuery()
            ->getResult();
    }
}
