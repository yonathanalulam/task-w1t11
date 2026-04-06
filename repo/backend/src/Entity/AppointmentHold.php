<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppointmentHoldRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppointmentHoldRepository::class)]
#[ORM\Table(name: 'appointment_holds')]
class AppointmentHold
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_RELEASED = 'RELEASED';
    public const STATUS_CONVERTED = 'CONVERTED';
    public const STATUS_EXPIRED = 'EXPIRED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'slot_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AppointmentSlot $slot;

    #[ORM\Column(name: 'held_by_username', length: 180)]
    private string $heldByUsername;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    #[ORM\Column(name: 'expires_at_utc')]
    private \DateTimeImmutable $expiresAtUtc;

    #[ORM\Column(name: 'released_at_utc', nullable: true)]
    private ?\DateTimeImmutable $releasedAtUtc = null;

    public function __construct(AppointmentSlot $slot, string $heldByUsername, \DateTimeImmutable $expiresAtUtc)
    {
        $this->slot = $slot;
        $this->heldByUsername = $heldByUsername;
        $this->expiresAtUtc = $expiresAtUtc;
        $this->createdAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlot(): AppointmentSlot
    {
        return $this->slot;
    }

    public function getHeldByUsername(): string
    {
        return $this->heldByUsername;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExpiresAtUtc(): \DateTimeImmutable
    {
        return $this->expiresAtUtc;
    }

    public function isActiveAt(\DateTimeImmutable $atUtc): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->expiresAtUtc > $atUtc;
    }

    public function markReleased(): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return;
        }

        $this->status = self::STATUS_RELEASED;
        $this->releasedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markConverted(): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            throw new \LogicException('Only active hold can be converted.');
        }

        $this->status = self::STATUS_CONVERTED;
        $this->releasedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markExpired(\DateTimeImmutable $nowUtc): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return;
        }

        if ($this->expiresAtUtc > $nowUtc) {
            return;
        }

        $this->status = self::STATUS_EXPIRED;
        $this->releasedAtUtc = $nowUtc;
    }
}
