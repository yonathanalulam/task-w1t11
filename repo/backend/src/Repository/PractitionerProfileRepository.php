<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PractitionerProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PractitionerProfile>
 */
class PractitionerProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PractitionerProfile::class);
    }

    public function findOneByUser(User $user): ?PractitionerProfile
    {
        return $this->findOneBy(['user' => $user]);
    }
}
