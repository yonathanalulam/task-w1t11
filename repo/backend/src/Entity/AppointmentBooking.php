<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppointmentBookingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppointmentBookingRepository::class)]
#[ORM\Table(name: 'appointment_bookings')]
class AppointmentBooking
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_CANCELLED = 'CANCELLED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'slot_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AppointmentSlot $slot;

    #[ORM\Column(name: 'booked_by_username', length: 180)]
    private string $bookedByUsername;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    #[ORM\Column(name: 'updated_at_utc')]
    private \DateTimeImmutable $updatedAtUtc;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'cancelled_at_utc', nullable: true)]
    private ?\DateTimeImmutable $cancelledAtUtc = null;

    #[ORM\Column(name: 'cancelled_by_username', length: 180, nullable: true)]
    private ?string $cancelledByUsername = null;

    #[ORM\Column(name: 'cancellation_reason', type: 'text', nullable: true)]
    private ?string $cancellationReason = null;

    #[ORM\Column(name: 'reschedule_count', options: ['default' => 0])]
    private int $rescheduleCount = 0;

    public function __construct(AppointmentSlot $slot, string $bookedByUsername)
    {
        $this->slot = $slot;
        $this->bookedByUsername = $bookedByUsername;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAtUtc = $now;
        $this->updatedAtUtc = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlot(): AppointmentSlot
    {
        return $this->slot;
    }

    public function getBookedByUsername(): string
    {
        return $this->bookedByUsername;
    }

    public function getCancelledAtUtc(): ?\DateTimeImmutable
    {
        return $this->cancelledAtUtc;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRescheduleCount(): int
    {
        return $this->rescheduleCount;
    }

    public function getUpdatedAtUtc(): \DateTimeImmutable
    {
        return $this->updatedAtUtc;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function cancel(string $actorUsername, ?string $reason = null): void
    {
        if ($this->isCancelled()) {
            throw new \LogicException('Booking already cancelled.');
        }

        $this->status = self::STATUS_CANCELLED;
        $this->cancelledByUsername = $actorUsername;
        $this->cancellationReason = $reason;
        $this->cancelledAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    public function rescheduleTo(AppointmentSlot $slot): void
    {
        if ($this->isCancelled()) {
            throw new \LogicException('Cancelled bookings cannot be rescheduled.');
        }

        $this->slot = $slot;
        ++$this->rescheduleCount;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
