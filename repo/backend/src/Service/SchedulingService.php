<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppointmentBooking;
use App\Entity\AppointmentHold;
use App\Entity\AppointmentSlot;
use App\Entity\SchedulingConfiguration;
use App\Exception\SchedulingFlowException;
use App\Repository\AppointmentBookingRepository;
use App\Repository\AppointmentHoldRepository;
use App\Repository\AppointmentSlotRepository;
use App\Repository\SchedulingConfigurationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class SchedulingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppointmentSlotRepository $slots,
        private readonly AppointmentBookingRepository $bookings,
        private readonly AppointmentHoldRepository $holds,
        private readonly SchedulingConfigurationRepository $configurations,
    ) {
    }

    public function configuration(): ?SchedulingConfiguration
    {
        return $this->configurations->latest();
    }

    /** @param array<int, array<string, mixed>> $weeklyAvailability */
    public function upsertConfiguration(
        string $practitionerName,
        string $locationName,
        int $slotDurationMinutes,
        int $slotCapacity,
        array $weeklyAvailability,
        string $actorUsername,
    ): SchedulingConfiguration {
        $existing = $this->configurations->latest();
        if ($existing instanceof SchedulingConfiguration) {
            $existing->update(
                $practitionerName,
                $locationName,
                $slotDurationMinutes,
                $slotCapacity,
                $weeklyAvailability,
                $actorUsername,
            );

            $this->entityManager->flush();

            return $existing;
        }

        $configuration = new SchedulingConfiguration(
            $practitionerName,
            $locationName,
            $slotDurationMinutes,
            $slotCapacity,
            $weeklyAvailability,
            $actorUsername,
        );

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return $configuration;
    }

    public function generateSlotsFromConfiguration(int $daysAhead, string $actorUsername): array
    {
        $configuration = $this->configurations->latest();
        if (!$configuration instanceof SchedulingConfiguration) {
            throw new SchedulingFlowException('SCHEDULING_CONFIG_REQUIRED', Response::HTTP_CONFLICT, 'Scheduling configuration is required before slot generation.');
        }

        if ($daysAhead < 1 || $daysAhead > 90) {
            throw new SchedulingFlowException('INVALID_DAYS_AHEAD', Response::HTTP_UNPROCESSABLE_ENTITY, 'daysAhead must be between 1 and 90.');
        }

        $now = $this->nowUtc();
        $rangeStart = $now->setTime(0, 0, 0);
        $rangeEnd = $rangeStart->modify(sprintf('+%d days', $daysAhead));

        $created = [];

        $cursor = $rangeStart;
        while ($cursor < $rangeEnd) {
            $weekday = (int) $cursor->format('N');
            foreach ($configuration->getWeeklyAvailability() as $window) {
                if ((int) ($window['weekday'] ?? 0) !== $weekday) {
                    continue;
                }

                $windowStart = $this->windowDateTime($cursor, (string) ($window['startTime'] ?? ''));
                $windowEnd = $this->windowDateTime($cursor, (string) ($window['endTime'] ?? ''));
                if ($windowEnd <= $windowStart) {
                    continue;
                }

                $slotStart = $windowStart;
                while ($slotStart < $windowEnd) {
                    $slotEnd = $slotStart->modify(sprintf('+%d minutes', $configuration->getSlotDurationMinutes()));
                    if ($slotEnd > $windowEnd) {
                        break;
                    }

                    if ($this->slots->hasOverlap(
                        $configuration->getPractitionerName(),
                        $configuration->getLocationName(),
                        $slotStart,
                        $slotEnd,
                    )) {
                        throw new SchedulingFlowException(
                            'OVERLAPPING_SLOT',
                            Response::HTTP_CONFLICT,
                            'Cannot generate overlapping slots for the same practitioner and location.',
                            [
                                ['field' => 'startAtUtc', 'issue' => $slotStart->format(DATE_ATOM)],
                                ['field' => 'endAtUtc', 'issue' => $slotEnd->format(DATE_ATOM)],
                            ],
                        );
                    }

                    $slot = new AppointmentSlot(
                        $slotStart,
                        $slotEnd,
                        $configuration->getSlotCapacity(),
                        $configuration->getPractitionerName(),
                        $configuration->getLocationName(),
                        $actorUsername,
                    );
                    $this->entityManager->persist($slot);
                    $created[] = $slot;

                    $slotStart = $slotEnd;
                }
            }

            $cursor = $cursor->modify('+1 day');
        }

        $this->entityManager->flush();

        return $created;
    }

    public function placeHold(int $slotId, string $username): AppointmentHold
    {
        return $this->inTransaction(function () use ($slotId, $username): AppointmentHold {
            $slot = $this->lockSlot($slotId);
            $now = $this->nowUtc();
            $this->assertSlotBookable($slot, $now);
            $this->assertWithinBookingHorizon($slot->getStartAtUtc(), $now);

            $this->holds->expireActiveForSlot($slot, $now);

            $activeHoldMap = $this->holds->activeHoldMapForUser([$slot], $username, $now);
            $existing = $slot->getId() !== null ? ($activeHoldMap[$slot->getId()] ?? null) : null;
            if ($existing instanceof AppointmentHold) {
                return $existing;
            }

            if ($this->bookings->existsActiveBookingForUser($slot, $username)) {
                throw new SchedulingFlowException('ALREADY_BOOKED', Response::HTTP_CONFLICT, 'You already have an active booking for this slot.');
            }

            $activeHolds = $this->holds->countActiveForSlot($slot, $now);
            $available = $slot->getCapacity() - $slot->getBookedCount() - $activeHolds;
            if ($available <= 0) {
                throw new SchedulingFlowException('SLOT_UNAVAILABLE', Response::HTTP_CONFLICT, 'Slot is currently unavailable due to existing bookings or active holds.');
            }

            $hold = new AppointmentHold($slot, $username, $now->modify('+10 minutes'));
            $this->entityManager->persist($hold);
            $this->entityManager->flush();

            return $hold;
        });
    }

    public function releaseHold(int $holdId, string $username, bool $adminOverride): void
    {
        $this->inTransaction(function () use ($holdId, $username, $adminOverride): void {
            $hold = $this->lockHold($holdId);
            $slot = $this->lockSlot($hold->getSlot()->getId() ?? 0);
            $now = $this->nowUtc();
            $this->holds->expireActiveForSlot($slot, $now);

            if ($hold->getHeldByUsername() !== $username && !$adminOverride) {
                throw new SchedulingFlowException('ACCESS_DENIED', Response::HTTP_FORBIDDEN, 'Cannot release hold owned by another user.');
            }

            if (!$hold->isActiveAt($now)) {
                throw new SchedulingFlowException('HOLD_NOT_ACTIVE', Response::HTTP_CONFLICT, 'Hold is no longer active.');
            }

            $hold->markReleased();
            $this->entityManager->flush();
        });
    }

    public function bookFromHold(int $holdId, string $username): AppointmentBooking
    {
        return $this->inTransaction(function () use ($holdId, $username): AppointmentBooking {
            $hold = $this->lockHold($holdId);
            $slot = $this->lockSlot($hold->getSlot()->getId() ?? 0);
            $now = $this->nowUtc();
            $this->assertSlotBookable($slot, $now);
            $this->holds->expireActiveForSlot($slot, $now);

            if ($hold->getHeldByUsername() !== $username) {
                throw new SchedulingFlowException('ACCESS_DENIED', Response::HTTP_FORBIDDEN, 'Hold belongs to another user.');
            }

            if (!$hold->isActiveAt($now)) {
                throw new SchedulingFlowException('HOLD_NOT_ACTIVE', Response::HTTP_CONFLICT, 'Hold expired before booking confirmation.');
            }

            if ($this->bookings->existsActiveBookingForUser($slot, $username)) {
                throw new SchedulingFlowException('ALREADY_BOOKED', Response::HTTP_CONFLICT, 'You already have an active booking for this slot.');
            }

            if ($this->bookings->hasActiveOverlapForPractitionerLocation(
                $slot->getPractitionerName(),
                $slot->getLocationName(),
                $slot->getStartAtUtc(),
                $slot->getEndAtUtc(),
            )) {
                throw new SchedulingFlowException(
                    'PRACTITIONER_LOCATION_CONFLICT',
                    Response::HTTP_CONFLICT,
                    'This practitioner/location already has an overlapping booking.',
                );
            }

            $booking = new AppointmentBooking($slot, $username);
            $slot->reserveOne();
            $hold->markConverted();

            $this->entityManager->persist($booking);
            $this->entityManager->flush();

            return $booking;
        });
    }

    /** @return list<AppointmentSlot> */
    public function listSlots(?\DateTimeImmutable $startUtc = null, ?\DateTimeImmutable $endUtc = null): array
    {
        return $this->slots->findUpcomingActive($startUtc, $endUtc);
    }

    /** @return list<AppointmentBooking> */
    public function listBookingsForUser(string $username): array
    {
        return $this->bookings->findActiveForUser($username);
    }

    public function cancelBooking(
        int $bookingId,
        string $actorUsername,
        bool $adminOverride,
        ?string $reason,
    ): AppointmentBooking {
        return $this->inTransaction(function () use ($bookingId, $actorUsername, $adminOverride, $reason): AppointmentBooking {
            $booking = $this->lockBooking($bookingId);
            $slot = $this->lockSlot($booking->getSlot()->getId() ?? 0);

            if ($booking->getBookedByUsername() !== $actorUsername && !$adminOverride) {
                throw new SchedulingFlowException('ACCESS_DENIED', Response::HTTP_FORBIDDEN, 'Cannot cancel booking owned by another user.');
            }

            if ($booking->isCancelled()) {
                throw new SchedulingFlowException('BOOKING_ALREADY_CANCELLED', Response::HTTP_CONFLICT, 'Booking is already cancelled.');
            }

            $now = $this->nowUtc();
            if (!$adminOverride && $slot->getStartAtUtc() <= $now->modify('+24 hours')) {
                throw new SchedulingFlowException(
                    'CANCEL_WINDOW_RESTRICTED',
                    Response::HTTP_CONFLICT,
                    'Cancellations inside 24 hours require system-admin override.',
                );
            }

            $booking->cancel($actorUsername, $reason);
            $slot->releaseOne();
            $this->entityManager->flush();

            return $booking;
        });
    }

    public function rescheduleBooking(int $bookingId, int $targetSlotId, string $actorUsername, bool $adminOverride): AppointmentBooking
    {
        return $this->inTransaction(function () use ($bookingId, $targetSlotId, $actorUsername, $adminOverride): AppointmentBooking {
            $booking = $this->lockBooking($bookingId);
            if ($booking->isCancelled()) {
                throw new SchedulingFlowException('BOOKING_ALREADY_CANCELLED', Response::HTTP_CONFLICT, 'Cancelled booking cannot be rescheduled.');
            }

            if ($booking->getBookedByUsername() !== $actorUsername && !$adminOverride) {
                throw new SchedulingFlowException('ACCESS_DENIED', Response::HTTP_FORBIDDEN, 'Cannot reschedule booking owned by another user.');
            }

            if ($booking->getRescheduleCount() >= 2) {
                throw new SchedulingFlowException('RESCHEDULE_LIMIT_REACHED', Response::HTTP_CONFLICT, 'Reschedule limit reached for this appointment.');
            }

            $currentSlot = $booking->getSlot();
            $lockIds = [$currentSlot->getId() ?? 0, $targetSlotId];
            sort($lockIds);

            $lockedSlots = [];
            foreach ($lockIds as $id) {
                $lockedSlots[$id] = $this->lockSlot($id);
            }

            $sourceSlot = $lockedSlots[$currentSlot->getId() ?? 0] ?? $currentSlot;
            $targetSlot = $lockedSlots[$targetSlotId] ?? null;
            if (!$targetSlot instanceof AppointmentSlot) {
                throw new SchedulingFlowException('NOT_FOUND', Response::HTTP_NOT_FOUND, 'Target slot not found.');
            }

            $now = $this->nowUtc();
            $this->assertSlotBookable($targetSlot, $now);
            $this->assertWithinBookingHorizon($targetSlot->getStartAtUtc(), $now);
            $this->holds->expireActiveForSlot($targetSlot, $now);

            $activeHolds = $this->holds->countActiveForSlot($targetSlot, $now);
            $available = $targetSlot->getCapacity() - $targetSlot->getBookedCount() - $activeHolds;
            if ($available <= 0) {
                throw new SchedulingFlowException('SLOT_UNAVAILABLE', Response::HTTP_CONFLICT, 'Target slot has no remaining capacity.');
            }

            if ($this->bookings->hasActiveOverlapForPractitionerLocation(
                $targetSlot->getPractitionerName(),
                $targetSlot->getLocationName(),
                $targetSlot->getStartAtUtc(),
                $targetSlot->getEndAtUtc(),
                $booking->getId(),
            )) {
                throw new SchedulingFlowException(
                    'PRACTITIONER_LOCATION_CONFLICT',
                    Response::HTTP_CONFLICT,
                    'Cannot reschedule into overlapping practitioner/location booking window.',
                );
            }

            if (($sourceSlot->getId() ?? 0) !== ($targetSlot->getId() ?? -1)) {
                $sourceSlot->releaseOne();
                $targetSlot->reserveOne();
                $booking->rescheduleTo($targetSlot);
            }

            $this->entityManager->flush();

            return $booking;
        });
    }

    private function lockSlot(int $slotId): AppointmentSlot
    {
        if ($slotId <= 0) {
            throw new SchedulingFlowException('NOT_FOUND', Response::HTTP_NOT_FOUND, 'Appointment slot not found.');
        }

        $conn = $this->entityManager->getConnection();
        $row = $conn->fetchAssociative('SELECT id FROM appointment_slots WHERE id = ? FOR UPDATE', [$slotId]);
        if (!is_array($row)) {
            throw new SchedulingFlowException('NOT_FOUND', Response::HTTP_NOT_FOUND, 'Appointment slot not found.');
        }

        $slot = $this->slots->find($slotId);
        if (!$slot instanceof AppointmentSlot) {
            throw new SchedulingFlowException('NOT_FOUND', Response::HTTP_NOT_FOUND, 'Appointment slot not found.');
        }

        return $slot;
    }

    private function lockHold(int $holdId): AppointmentHold
    {
        if ($holdId <= 0) {
            throw new SchedulingFlowException('NOT_FOUND', Response::HTTP_NOT_FOUND, 'Appointment hold not found.');
        }

        $conn = $this->entityManager->getConnection();
        $row = $conn->fetchAssociative('SELECT id FROM appointment_holds WHERE id = ? FOR UPDATE', [$holdId]);
        if (!is_array($row)) {
            throw new SchedulingFlowException('NOT_FOUND', Response::HTTP_NOT_FOUND, 'Appointment hold not found.');
        }

        $hold = $this->holds->find($holdId);
        if (!$hold instanceof AppointmentHold) {
            throw new SchedulingFlowException('NOT_FOUND', Response::HTTP_NOT_FOUND, 'Appointment hold not found.');
        }

        return $hold;
    }

    private function lockBooking(int $bookingId): AppointmentBooking
    {
        if ($bookingId <= 0) {
            throw new SchedulingFlowException('NOT_FOUND', Response::HTTP_NOT_FOUND, 'Appointment booking not found.');
        }

        $conn = $this->entityManager->getConnection();
        $row = $conn->fetchAssociative('SELECT id FROM appointment_bookings WHERE id = ? FOR UPDATE', [$bookingId]);
        if (!is_array($row)) {
            throw new SchedulingFlowException('NOT_FOUND', Response::HTTP_NOT_FOUND, 'Appointment booking not found.');
        }

        $booking = $this->bookings->find($bookingId);
        if (!$booking instanceof AppointmentBooking) {
            throw new SchedulingFlowException('NOT_FOUND', Response::HTTP_NOT_FOUND, 'Appointment booking not found.');
        }

        return $booking;
    }

    private function assertSlotBookable(AppointmentSlot $slot, \DateTimeImmutable $now): void
    {
        if ($slot->getStatus() !== AppointmentSlot::STATUS_ACTIVE) {
            throw new SchedulingFlowException('SLOT_UNAVAILABLE', Response::HTTP_CONFLICT, 'Appointment slot is not active.');
        }

        if ($slot->getStartAtUtc() <= $now) {
            throw new SchedulingFlowException('SLOT_UNAVAILABLE', Response::HTTP_CONFLICT, 'Appointment slot is no longer available.');
        }
    }

    private function assertWithinBookingHorizon(\DateTimeImmutable $slotStartAtUtc, \DateTimeImmutable $now): void
    {
        if ($slotStartAtUtc > $now->modify('+90 days')) {
            throw new SchedulingFlowException(
                'BOOKING_HORIZON_EXCEEDED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Bookings cannot be made more than 90 days in advance.',
            );
        }
    }

    private function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function windowDateTime(\DateTimeImmutable $day, string $time): \DateTimeImmutable
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches)) {
            throw new SchedulingFlowException('INVALID_WEEKLY_AVAILABILITY', Response::HTTP_UNPROCESSABLE_ENTITY, 'Weekly availability contains invalid time values.');
        }

        return $day->setTime((int) $matches[1], (int) $matches[2], 0);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function inTransaction(callable $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }
}
