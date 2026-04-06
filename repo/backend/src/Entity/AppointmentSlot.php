<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppointmentSlotRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppointmentSlotRepository::class)]
#[ORM\Table(name: 'appointment_slots')]
class AppointmentSlot
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_CANCELLED = 'CANCELLED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'start_at_utc')]
    private \DateTimeImmutable $startAtUtc;

    #[ORM\Column(name: 'end_at_utc')]
    private \DateTimeImmutable $endAtUtc;

    #[ORM\Column]
    private int $capacity;

    #[ORM\Column(name: 'booked_count', options: ['default' => 0])]
    private int $bookedCount = 0;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'practitioner_name', length: 180)]
    private string $practitionerName;

    #[ORM\Column(name: 'location_name', length: 180)]
    private string $locationName;

    #[ORM\Column(name: 'created_by_username', length: 180)]
    private string $createdByUsername;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    #[ORM\Column(name: 'updated_at_utc')]
    private \DateTimeImmutable $updatedAtUtc;

    public function __construct(
        \DateTimeImmutable $startAtUtc,
        \DateTimeImmutable $endAtUtc,
        int $capacity,
        string $practitionerName,
        string $locationName,
        string $createdByUsername,
    )
    {
        $this->startAtUtc = $startAtUtc;
        $this->endAtUtc = $endAtUtc;
        $this->capacity = max(1, $capacity);
        $this->practitionerName = $practitionerName;
        $this->locationName = $locationName;
        $this->createdByUsername = $createdByUsername;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAtUtc = $now;
        $this->updatedAtUtc = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartAtUtc(): \DateTimeImmutable
    {
        return $this->startAtUtc;
    }

    public function getEndAtUtc(): \DateTimeImmutable
    {
        return $this->endAtUtc;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getBookedCount(): int
    {
        return $this->bookedCount;
    }

    public function getRemainingCapacity(): int
    {
        return max(0, $this->capacity - $this->bookedCount);
    }

    public function canBook(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->bookedCount < $this->capacity;
    }

    public function reserveOne(): void
    {
        if (!$this->canBook()) {
            throw new \LogicException('Slot is not available for booking.');
        }

        ++$this->bookedCount;
        $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedByUsername(): string
    {
        return $this->createdByUsername;
    }

    public function getPractitionerName(): string
    {
        return $this->practitionerName;
    }

    public function getLocationName(): string
    {
        return $this->locationName;
    }

    public function releaseOne(): void
    {
        if ($this->bookedCount <= 0) {
            throw new \LogicException('Slot has no bookings to release.');
        }

        --$this->bookedCount;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
