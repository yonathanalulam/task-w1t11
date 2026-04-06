<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppointmentSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppointmentSlot>
 */
class AppointmentSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppointmentSlot::class);
    }

    /** @return list<AppointmentSlot> */
    public function findUpcomingActive(?\DateTimeImmutable $startUtc = null, ?\DateTimeImmutable $endUtc = null): array
    {
        $qb = $this->createQueryBuilder('slot')
            ->where('slot.status = :status')
            ->andWhere('slot.endAtUtc >= :now')
            ->setParameter('status', AppointmentSlot::STATUS_ACTIVE)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->orderBy('slot.startAtUtc', 'ASC');

        if ($startUtc instanceof \DateTimeImmutable) {
            $qb->andWhere('slot.startAtUtc >= :start')->setParameter('start', $startUtc);
        }

        if ($endUtc instanceof \DateTimeImmutable) {
            $qb->andWhere('slot.startAtUtc <= :end')->setParameter('end', $endUtc);
        }

        return $qb->getQuery()->getResult();
    }

    public function hasOverlap(
        string $practitionerName,
        string $locationName,
        \DateTimeImmutable $startAtUtc,
        \DateTimeImmutable $endAtUtc,
    ): bool {
        $count = $this->createQueryBuilder('slot')
            ->select('COUNT(slot.id)')
            ->where('slot.status = :status')
            ->andWhere('slot.practitionerName = :practitioner')
            ->andWhere('slot.locationName = :location')
            ->andWhere('slot.startAtUtc < :endAt')
            ->andWhere('slot.endAtUtc > :startAt')
            ->setParameter('status', AppointmentSlot::STATUS_ACTIVE)
            ->setParameter('practitioner', $practitionerName)
            ->setParameter('location', $locationName)
            ->setParameter('startAt', $startAtUtc)
            ->setParameter('endAt', $endAtUtc)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
