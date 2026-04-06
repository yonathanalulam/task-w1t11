<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnalyticsSampleDataset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsSampleDataset>
 */
class AnalyticsSampleDatasetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsSampleDataset::class);
    }

    /** @return list<AnalyticsSampleDataset> */
    public function listAll(): array
    {
        return $this->createQueryBuilder('dataset')
            ->orderBy('dataset.createdAtUtc', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @param list<int> $ids @return list<AnalyticsSampleDataset> */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('dataset')
            ->where('dataset.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
