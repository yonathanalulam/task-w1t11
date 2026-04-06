<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AnalyticsFeatureDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalyticsFeatureDefinitionRepository::class)]
#[ORM\Table(name: 'analytics_feature_definitions')]
class AnalyticsFeatureDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $tags;

    #[ORM\Column(name: 'formula_expression', type: 'text')]
    private string $formulaExpression;

    #[ORM\Column(name: 'created_by_username', length: 180)]
    private string $createdByUsername;

    #[ORM\Column(name: 'updated_by_username', length: 180)]
    private string $updatedByUsername;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    #[ORM\Column(name: 'updated_at_utc')]
    private \DateTimeImmutable $updatedAtUtc;

    /** @param list<string> $tags */
    public function __construct(string $name, string $description, array $tags, string $formulaExpression, string $actorUsername)
    {
        $this->name = $name;
        $this->description = $description;
        $this->tags = $tags;
        $this->formulaExpression = $formulaExpression;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getFormulaExpression(): string
    {
        return $this->formulaExpression;
    }

    public function getUpdatedAtUtc(): \DateTimeImmutable
    {
        return $this->updatedAtUtc;
    }

    /** @param list<string> $tags */
    public function update(string $name, string $description, array $tags, string $formulaExpression, string $actorUsername): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->tags = $tags;
        $this->formulaExpression = $formulaExpression;
        $this->updatedByUsername = $actorUsername;
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
