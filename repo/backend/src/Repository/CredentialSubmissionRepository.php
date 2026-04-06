<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CredentialSubmission;
use App\Entity\PractitionerProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CredentialSubmission>
 */
class CredentialSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CredentialSubmission::class);
    }

    /** @return list<CredentialSubmission> */
    public function findByProfile(PractitionerProfile $profile): array
    {
        return $this->createQueryBuilder('submission')
            ->where('submission.practitionerProfile = :profile')
            ->setParameter('profile', $profile)
            ->orderBy('submission.updatedAtUtc', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneOwnedById(int $submissionId, User $user): ?CredentialSubmission
    {
        return $this->createQueryBuilder('submission')
            ->join('submission.practitionerProfile', 'profile')
            ->where('submission.id = :id')
            ->andWhere('profile.user = :user')
            ->setParameter('id', $submissionId)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<CredentialSubmission> */
    public function findReviewQueue(?string $status = null): array
    {
        $qb = $this->createQueryBuilder('submission')
            ->join('submission.practitionerProfile', 'profile')
            ->join('profile.user', 'user')
            ->addSelect('profile', 'user')
            ->orderBy('submission.updatedAtUtc', 'DESC');

        if ($status !== null) {
            $qb->andWhere('submission.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<CredentialSubmission> */
    public function listRecentForAdmin(int $limit = 60): array
    {
        $limit = max(1, min(200, $limit));

        return $this->createQueryBuilder('submission')
            ->join('submission.practitionerProfile', 'profile')
            ->join('profile.user', 'user')
            ->addSelect('profile', 'user')
            ->orderBy('submission.updatedAtUtc', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
