<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SchedulingConfigurationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SchedulingConfigurationRepository::class)]
#[ORM\Table(name: 'scheduling_configurations')]
class SchedulingConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'practitioner_name', length: 180)]
    private string $practitionerName;

    #[ORM\Column(name: 'location_name', length: 180)]
    private string $locationName;

    #[ORM\Column(name: 'slot_duration_minutes', options: ['default' => 30])]
    private int $slotDurationMinutes = 30;

    #[ORM\Column(name: 'slot_capacity', options: ['default' => 1])]
    private int $slotCapacity = 1;

    /** @var array<int, array<string, mixed>> */
    #[ORM\Column(name: 'weekly_availability', type: 'json')]
    private array $weeklyAvailability;

    #[ORM\Column(name: 'created_by_username', length: 180)]
    private string $createdByUsername;

    #[ORM\Column(name: 'updated_by_username', length: 180)]
    private string $updatedByUsername;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    #[ORM\Column(name: 'updated_at_utc')]
    private \DateTimeImmutable $updatedAtUtc;

    /** @param array<int, array<string, mixed>> $weeklyAvailability */
    public function __construct(
        string $practitionerName,
        string $locationName,
        int $slotDurationMinutes,
        int $slotCapacity,
        array $weeklyAvailability,
        string $actorUsername,
    ) {
        $this->practitionerName = $practitionerName;
        $this->locationName = $locationName;
        $this->slotDurationMinutes = $slotDurationMinutes;
        $this->slotCapacity = $slotCapacity;
        $this->weeklyAvailability = $weeklyAvailability;
        $this->createdByUsername = $actorUsername;
        $this->updatedByUsername = $actorUsername;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAtUtc = $now;
        $this->updatedAtUtc = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPractitionerName(): string
    {
        return $this->practitionerName;
    }

    public function getLocationName(): string
    {
        return $this->locationName;
    }

    public function getSlotDurationMinutes(): int
    {
        return $this->slotDurationMinutes;
    }

    public function getSlotCapacity(): int
    {
        return $this->slotCapacity;
    }

    /** @return array<int, array<string, mixed>> */
    public function getWeeklyAvailability(): array
    {
        return $this->weeklyAvailability;
    }

    public function getUpdatedAtUtc(): \DateTimeImmutable
    {
        return $this->updatedAtUtc;
    }

    /** @param array<int, array<string, mixed>> $weeklyAvailability */
    public function update(
        string $practitionerName,
        string $locationName,
        int $slotDurationMinutes,
        int $slotCapacity,
        array $weeklyAvailability,
        string $actorUsername,
    ): void {
        $this->practitionerName = $practitionerName;
        $this->locationName = $locationName;
        $this->slotDurationMinutes = $slotDurationMinutes;
        $this->slotCapacity = $slotCapacity;
        $this->weeklyAvailability = $weeklyAvailability;
        $this->updatedByUsername = $actorUsername;
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
