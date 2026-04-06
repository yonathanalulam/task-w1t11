<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppointmentHold;
use App\Entity\AppointmentSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppointmentHold>
 */
class AppointmentHoldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppointmentHold::class);
    }

    public function countActiveForSlot(AppointmentSlot $slot, \DateTimeImmutable $nowUtc): int
    {
        $count = $this->createQueryBuilder('hold')
            ->select('COUNT(hold.id)')
            ->where('hold.slot = :slot')
            ->andWhere('hold.status = :status')
            ->andWhere('hold.expiresAtUtc > :now')
            ->setParameter('slot', $slot)
            ->setParameter('status', AppointmentHold::STATUS_ACTIVE)
            ->setParameter('now', $nowUtc)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function expireActiveForSlot(AppointmentSlot $slot, \DateTimeImmutable $nowUtc): void
    {
        $this->createQueryBuilder('hold')
            ->update()
            ->set('hold.status', ':expired')
            ->set('hold.releasedAtUtc', ':now')
            ->where('hold.slot = :slot')
            ->andWhere('hold.status = :active')
            ->andWhere('hold.expiresAtUtc <= :now')
            ->setParameter('expired', AppointmentHold::STATUS_EXPIRED)
            ->setParameter('active', AppointmentHold::STATUS_ACTIVE)
            ->setParameter('now', $nowUtc)
            ->setParameter('slot', $slot)
            ->getQuery()
            ->execute();
    }

    /** @param list<AppointmentSlot> $slots @return array<int, int> */
    public function activeCountMapForSlots(array $slots, \DateTimeImmutable $nowUtc): array
    {
        if ($slots === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('hold')
            ->select('IDENTITY(hold.slot) AS slotId, COUNT(hold.id) AS holdCount')
            ->where('hold.slot IN (:slots)')
            ->andWhere('hold.status = :status')
            ->andWhere('hold.expiresAtUtc > :now')
            ->groupBy('hold.slot')
            ->setParameter('slots', $slots)
            ->setParameter('status', AppointmentHold::STATUS_ACTIVE)
            ->setParameter('now', $nowUtc)
            ->getQuery()
            ->getScalarResult();

        $map = [];
        foreach ($rows as $row) {
            $slotId = isset($row['slotId']) ? (int) $row['slotId'] : 0;
            $holdCount = isset($row['holdCount']) ? (int) $row['holdCount'] : 0;
            if ($slotId > 0) {
                $map[$slotId] = $holdCount;
            }
        }

        return $map;
    }

    /** @param list<AppointmentSlot> $slots @return array<int, AppointmentHold> */
    public function activeHoldMapForUser(array $slots, string $username, \DateTimeImmutable $nowUtc): array
    {
        if ($slots === []) {
            return [];
        }

        /** @var list<AppointmentHold> $rows */
        $rows = $this->createQueryBuilder('hold')
            ->where('hold.slot IN (:slots)')
            ->andWhere('hold.heldByUsername = :username')
            ->andWhere('hold.status = :status')
            ->andWhere('hold.expiresAtUtc > :now')
            ->setParameter('slots', $slots)
            ->setParameter('username', $username)
            ->setParameter('status', AppointmentHold::STATUS_ACTIVE)
            ->setParameter('now', $nowUtc)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $hold) {
            $slotId = $hold->getSlot()->getId();
            if ($slotId !== null && !isset($map[$slotId])) {
                $map[$slotId] = $hold;
            }
        }

        return $map;
    }
}
