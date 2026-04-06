<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuestionBankEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestionBankEntry>
 */
class QuestionBankEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionBankEntry::class);
    }

    /** @return list<QuestionBankEntry> */
    public function listByStatus(string $statusFilter): array
    {
        $qb = $this->createQueryBuilder('entry')->orderBy('entry.updatedAtUtc', 'DESC');

        if ($statusFilter !== 'ALL') {
            $qb->where('entry.status = :status')->setParameter('status', $statusFilter);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<QuestionBankEntry> */
    public function findForSimilarityScan(int $excludeEntryId): array
    {
        return $this->createQueryBuilder('entry')
            ->where('entry.id <> :id')
            ->setParameter('id', $excludeEntryId)
            ->getQuery()
            ->getResult();
    }

    /** @return list<QuestionBankEntry> */
    public function listRecentForAdmin(int $limit = 60): array
    {
        $limit = max(1, min(200, $limit));

        return $this->createQueryBuilder('entry')
            ->orderBy('entry.updatedAtUtc', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
