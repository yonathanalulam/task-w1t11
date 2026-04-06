<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SensitiveAccessLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SensitiveAccessLogRepository::class)]
#[ORM\Table(name: 'sensitive_access_logs')]
class SensitiveAccessLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'actor_username', length: 180)]
    private string $actorUsername;

    #[ORM\Column(name: 'entity_type', length: 120)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id', length: 120)]
    private string $entityId;

    #[ORM\Column(name: 'field_name', length: 120)]
    private string $fieldName;

    #[ORM\Column(length: 255)]
    private string $reason;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    public function __construct(
        string $actorUsername,
        string $entityType,
        string $entityId,
        string $fieldName,
        string $reason,
    ) {
        $this->actorUsername = $actorUsername;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->fieldName = $fieldName;
        $this->reason = $reason;
        $this->createdAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActorUsername(): string
    {
        return $this->actorUsername;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAtUtc(): \DateTimeImmutable
    {
        return $this->createdAtUtc;
    }
}
