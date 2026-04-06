<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'actor_username', length: 180, nullable: true)]
    private ?string $actorUsername;

    #[ORM\Column(name: 'action_type', length: 120)]
    private string $actionType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    /** @param array<string, mixed> $payload */
    public function __construct(string $actionType, ?string $actorUsername, array $payload = [])
    {
        $this->actionType = $actionType;
        $this->actorUsername = $actorUsername;
        $this->payload = $payload;
        $this->createdAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActorUsername(): ?string
    {
        return $this->actorUsername;
    }

    public function getActionType(): string
    {
        return $this->actionType;
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
}
