<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CredentialSubmission;
use App\Entity\CredentialSubmissionVersion;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CredentialSubmissionVersion>
 */
class CredentialSubmissionVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CredentialSubmissionVersion::class);
    }

    /** @return list<CredentialSubmissionVersion> */
    public function findBySubmission(CredentialSubmission $submission): array
    {
        return $this->createQueryBuilder('version')
            ->where('version.submission = :submission')
            ->setParameter('submission', $submission)
            ->orderBy('version.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestBySubmission(CredentialSubmission $submission): ?CredentialSubmissionVersion
    {
        return $this->createQueryBuilder('version')
            ->where('version.submission = :submission')
            ->setParameter('submission', $submission)
            ->orderBy('version.versionNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneOwnedById(int $versionId, User $user): ?CredentialSubmissionVersion
    {
        return $this->createQueryBuilder('version')
            ->join('version.submission', 'submission')
            ->join('submission.practitionerProfile', 'profile')
            ->where('version.id = :id')
            ->andWhere('profile.user = :user')
            ->setParameter('id', $versionId)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneBySubmissionAndVersionNumber(CredentialSubmission $submission, int $versionNumber): ?CredentialSubmissionVersion
    {
        return $this->findOneBy([
            'submission' => $submission,
            'versionNumber' => $versionNumber,
        ]);
    }
}
