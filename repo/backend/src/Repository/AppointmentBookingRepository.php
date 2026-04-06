<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppointmentBooking;
use App\Entity\AppointmentSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppointmentBooking>
 */
class AppointmentBookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppointmentBooking::class);
    }

    public function existsActiveBookingForUser(AppointmentSlot $slot, string $username): bool
    {
        $count = $this->createQueryBuilder('booking')
            ->select('COUNT(booking.id)')
            ->where('booking.slot = :slot')
            ->andWhere('booking.bookedByUsername = :username')
            ->andWhere('booking.status = :status')
            ->setParameter('slot', $slot)
            ->setParameter('username', $username)
            ->setParameter('status', AppointmentBooking::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * @param list<AppointmentSlot> $slots
     * @return array<int, bool>
     */
    public function bookedSlotMapForUser(array $slots, string $username): array
    {
        if ($slots === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('booking')
            ->select('IDENTITY(booking.slot) AS slotId')
            ->where('booking.slot IN (:slots)')
            ->andWhere('booking.bookedByUsername = :username')
            ->andWhere('booking.status = :status')
            ->setParameter('slots', $slots)
            ->setParameter('username', $username)
            ->setParameter('status', AppointmentBooking::STATUS_ACTIVE)
            ->getQuery()
            ->getScalarResult();

        $map = [];
        foreach ($rows as $row) {
            $slotId = isset($row['slotId']) ? (int) $row['slotId'] : 0;
            if ($slotId > 0) {
                $map[$slotId] = true;
            }
        }

        return $map;
    }

    public function countActiveForSlot(AppointmentSlot $slot): int
    {
        $count = $this->createQueryBuilder('booking')
            ->select('COUNT(booking.id)')
            ->where('booking.slot = :slot')
            ->andWhere('booking.status = :status')
            ->setParameter('slot', $slot)
            ->setParameter('status', AppointmentBooking::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /** @return list<AppointmentBooking> */
    public function findActiveForUser(string $username): array
    {
        return $this->createQueryBuilder('booking')
            ->innerJoin('booking.slot', 'slot')
            ->addSelect('slot')
            ->where('booking.bookedByUsername = :username')
            ->andWhere('booking.status = :status')
            ->setParameter('username', $username)
            ->setParameter('status', AppointmentBooking::STATUS_ACTIVE)
            ->orderBy('slot.startAtUtc', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hasActiveOverlapForPractitionerLocation(
        string $practitionerName,
        string $locationName,
        \DateTimeImmutable $startAtUtc,
        \DateTimeImmutable $endAtUtc,
        ?int $excludeBookingId = null,
    ): bool {
        $qb = $this->createQueryBuilder('booking')
            ->select('COUNT(booking.id)')
            ->innerJoin('booking.slot', 'slot')
            ->where('booking.status = :status')
            ->andWhere('slot.status = :slotStatus')
            ->andWhere('slot.practitionerName = :practitioner')
            ->andWhere('slot.locationName = :location')
            ->andWhere('slot.startAtUtc < :endAt')
            ->andWhere('slot.endAtUtc > :startAt')
            ->setParameter('status', AppointmentBooking::STATUS_ACTIVE)
            ->setParameter('slotStatus', AppointmentSlot::STATUS_ACTIVE)
            ->setParameter('practitioner', $practitionerName)
            ->setParameter('location', $locationName)
            ->setParameter('startAt', $startAtUtc)
            ->setParameter('endAt', $endAtUtc);

        if ($excludeBookingId !== null) {
            $qb->andWhere('booking.id <> :excludeBookingId')->setParameter('excludeBookingId', $excludeBookingId);
        }

        $count = $qb->getQuery()->getSingleScalarResult();

        return (int) $count > 0;
    }
}
