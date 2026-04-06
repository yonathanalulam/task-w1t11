<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AnalyticsSampleDatasetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalyticsSampleDatasetRepository::class)]
#[ORM\Table(name: 'analytics_sample_datasets')]
class AnalyticsSampleDataset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(name: 'dataset_rows', type: 'json')]
    private array $rows;

    #[ORM\Column(name: 'created_by_username', length: 180)]
    private string $createdByUsername;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(string $name, string $description, array $rows, string $createdByUsername)
    {
        $this->name = $name;
        $this->description = $description;
        $this->rows = $rows;
        $this->createdByUsername = $createdByUsername;
        $this->createdAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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

    /** @return list<array<string, mixed>> */
    public function getRows(): array
    {
        return $this->rows;
    }

    public function getCreatedAtUtc(): \DateTimeImmutable
    {
        return $this->createdAtUtc;
    }
}
