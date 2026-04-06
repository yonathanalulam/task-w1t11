<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminAnomalyAlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminAnomalyAlertRepository::class)]
#[ORM\Table(name: 'admin_anomaly_alerts')]
#[ORM\UniqueConstraint(name: 'uniq_admin_alert_type_scope', columns: ['alert_type', 'scope_key'])]
#[ORM\Index(name: 'idx_admin_alert_status_detected', columns: ['status', 'last_detected_at_utc'])]
class AdminAnomalyAlert
{
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_ACKNOWLEDGED = 'ACKNOWLEDGED';
    public const STATUS_RESOLVED = 'RESOLVED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'alert_type', length: 120)]
    private string $alertType;

    #[ORM\Column(name: 'scope_key', length: 190)]
    private string $scopeKey;

    #[ORM\Column(name: 'status', length: 24)]
    private string $status = self::STATUS_OPEN;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'payload', type: Types::JSON)]
    private array $payload;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    #[ORM\Column(name: 'updated_at_utc')]
    private \DateTimeImmutable $updatedAtUtc;

    #[ORM\Column(name: 'last_detected_at_utc')]
    private \DateTimeImmutable $lastDetectedAtUtc;

    #[ORM\Column(name: 'acknowledged_at_utc', nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAtUtc = null;

    #[ORM\Column(name: 'acknowledged_by_username', length: 180, nullable: true)]
    private ?string $acknowledgedByUsername = null;

    #[ORM\Column(name: 'acknowledgement_note', type: Types::TEXT, nullable: true)]
    private ?string $acknowledgementNote = null;

    #[ORM\Column(name: 'resolved_at_utc', nullable: true)]
    private ?\DateTimeImmutable $resolvedAtUtc = null;

    /** @param array<string, mixed> $payload */
    public function __construct(string $alertType, string $scopeKey, array $payload)
    {
        $this->alertType = $alertType;
        $this->scopeKey = $scopeKey;
        $this->payload = $payload;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAtUtc = $now;
        $this->updatedAtUtc = $now;
        $this->lastDetectedAtUtc = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlertType(): string
    {
        return $this->alertType;
    }

    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAtUtc(): \DateTimeImmutable
    {
        return $this->createdAtUtc;
    }

    public function getUpdatedAtUtc(): \DateTimeImmutable
    {
        return $this->updatedAtUtc;
    }

    public function getLastDetectedAtUtc(): \DateTimeImmutable
    {
        return $this->lastDetectedAtUtc;
    }

    public function getAcknowledgedAtUtc(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAtUtc;
    }

    public function getAcknowledgedByUsername(): ?string
    {
        return $this->acknowledgedByUsername;
    }

    public function getAcknowledgementNote(): ?string
    {
        return $this->acknowledgementNote;
    }

    public function getResolvedAtUtc(): ?\DateTimeImmutable
    {
        return $this->resolvedAtUtc;
    }

    /** @param array<string, mixed> $payload */
    public function refreshOpen(array $payload): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->payload = $payload;
        $this->status = self::STATUS_OPEN;
        $this->lastDetectedAtUtc = $now;
        $this->updatedAtUtc = $now;
        $this->resolvedAtUtc = null;
        $this->acknowledgedAtUtc = null;
        $this->acknowledgedByUsername = null;
        $this->acknowledgementNote = null;
    }

    public function acknowledge(string $actorUsername, string $note): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->status = self::STATUS_ACKNOWLEDGED;
        $this->acknowledgedAtUtc = $now;
        $this->acknowledgedByUsername = $actorUsername;
        $this->acknowledgementNote = $note;
        $this->updatedAtUtc = $now;
    }

    public function resolve(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->status = self::STATUS_RESOLVED;
        $this->resolvedAtUtc = $now;
        $this->updatedAtUtc = $now;
    }
}
