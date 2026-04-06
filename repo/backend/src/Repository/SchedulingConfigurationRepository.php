<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchedulingConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchedulingConfiguration>
 */
class SchedulingConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchedulingConfiguration::class);
    }

    public function latest(): ?SchedulingConfiguration
    {
        return $this->createQueryBuilder('config')
            ->orderBy('config.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
