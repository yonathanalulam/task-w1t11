<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AnalyticsSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalyticsSnapshotRepository::class)]
#[ORM\Table(name: 'analytics_snapshots')]
#[ORM\Index(name: 'idx_analytics_snapshots_occurred_org', columns: ['occurred_at_utc', 'org_unit'])]
class AnalyticsSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'occurred_at_utc')]
    private \DateTimeImmutable $occurredAtUtc;

    #[ORM\Column(name: 'org_unit', length: 120)]
    private string $orgUnit;

    #[ORM\Column(name: 'intake_count')]
    private int $intakeCount;

    #[ORM\Column(name: 'breach_count')]
    private int $breachCount;

    #[ORM\Column(name: 'escalation_count')]
    private int $escalationCount;

    #[ORM\Column(name: 'avg_review_hours')]
    private float $avgReviewHours;

    #[ORM\Column(name: 'resolution_within_sla_pct')]
    private float $resolutionWithinSlaPct;

    #[ORM\Column(name: 'evidence_completeness_pct')]
    private float $evidenceCompletenessPct;

    public function __construct(
        \DateTimeImmutable $occurredAtUtc,
        string $orgUnit,
        int $intakeCount,
        int $breachCount,
        int $escalationCount,
        float $avgReviewHours,
        float $resolutionWithinSlaPct,
        float $evidenceCompletenessPct,
    ) {
        $this->occurredAtUtc = $occurredAtUtc;
        $this->orgUnit = $orgUnit;
        $this->intakeCount = max(0, $intakeCount);
        $this->breachCount = max(0, $breachCount);
        $this->escalationCount = max(0, $escalationCount);
        $this->avgReviewHours = max(0.0, $avgReviewHours);
        $this->resolutionWithinSlaPct = max(0.0, min(100.0, $resolutionWithinSlaPct));
        $this->evidenceCompletenessPct = max(0.0, min(100.0, $evidenceCompletenessPct));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAtUtc(): \DateTimeImmutable
    {
        return $this->occurredAtUtc;
    }

    public function getOrgUnit(): string
    {
        return $this->orgUnit;
    }

    public function getIntakeCount(): int
    {
        return $this->intakeCount;
    }

    public function getBreachCount(): int
    {
        return $this->breachCount;
    }

    public function getEscalationCount(): int
    {
        return $this->escalationCount;
    }

    public function getAvgReviewHours(): float
    {
        return $this->avgReviewHours;
    }

    public function getResolutionWithinSlaPct(): float
    {
        return $this->resolutionWithinSlaPct;
    }

    public function getEvidenceCompletenessPct(): float
    {
        return $this->evidenceCompletenessPct;
    }
}
