<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuestionBankEntry;
use App\Entity\QuestionBankEntryVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestionBankEntryVersion>
 */
class QuestionBankEntryVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionBankEntryVersion::class);
    }

    /** @return list<QuestionBankEntryVersion> */
    public function findByEntry(QuestionBankEntry $entry): array
    {
        return $this->createQueryBuilder('version')
            ->where('version.entry = :entry')
            ->setParameter('entry', $entry)
            ->orderBy('version.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByEntryAndVersionNumber(QuestionBankEntry $entry, int $versionNumber): ?QuestionBankEntryVersion
    {
        return $this->findOneBy([
            'entry' => $entry,
            'versionNumber' => $versionNumber,
        ]);
    }
}
