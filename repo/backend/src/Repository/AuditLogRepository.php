<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return list<AuditLog>
     */
    public function listRecent(?string $actorUsername, ?string $actionSubstring, ?\DateTimeImmutable $sinceUtc, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $qb = $this->createQueryBuilder('log')
            ->orderBy('log.id', 'DESC')
            ->setMaxResults($limit);

        if ($actorUsername !== null && $actorUsername !== '') {
            $qb->andWhere('log.actorUsername = :actor')->setParameter('actor', $actorUsername);
        }

        if ($actionSubstring !== null && $actionSubstring !== '') {
            $qb->andWhere('log.actionType LIKE :actionLike')->setParameter('actionLike', '%'.$actionSubstring.'%');
        }

        if ($sinceUtc instanceof \DateTimeImmutable) {
            $qb->andWhere('log.createdAtUtc >= :since')->setParameter('since', $sinceUtc);
        }

        return $qb->getQuery()->getResult();
    }
}
