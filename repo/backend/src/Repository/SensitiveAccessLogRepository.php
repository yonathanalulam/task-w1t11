<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SensitiveAccessLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SensitiveAccessLog>
 */
class SensitiveAccessLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SensitiveAccessLog::class);
    }

    /**
     * @return list<SensitiveAccessLog>
     */
    public function listRecent(?string $actorUsername, ?string $entityType, ?\DateTimeImmutable $sinceUtc, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $qb = $this->createQueryBuilder('log')
            ->orderBy('log.id', 'DESC')
            ->setMaxResults($limit);

        if ($actorUsername !== null && $actorUsername !== '') {
            $qb->andWhere('log.actorUsername = :actor')->setParameter('actor', $actorUsername);
        }

        if ($entityType !== null && $entityType !== '') {
            $qb->andWhere('log.entityType = :entityType')->setParameter('entityType', $entityType);
        }

        if ($sinceUtc instanceof \DateTimeImmutable) {
            $qb->andWhere('log.createdAtUtc >= :since')->setParameter('since', $sinceUtc);
        }

        return $qb->getQuery()->getResult();
    }
}
