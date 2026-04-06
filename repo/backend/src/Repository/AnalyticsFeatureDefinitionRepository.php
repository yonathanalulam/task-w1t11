<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnalyticsFeatureDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalyticsFeatureDefinition>
 */
class AnalyticsFeatureDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsFeatureDefinition::class);
    }

    /** @return list<AnalyticsFeatureDefinition> */
    public function listAll(): array
    {
        return $this->createQueryBuilder('feature')
            ->orderBy('feature.updatedAtUtc', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
