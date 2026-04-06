<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CredentialSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CredentialSubmissionRepository::class)]
#[ORM\Table(name: 'credential_submissions')]
class CredentialSubmission
{
    public const STATUS_PENDING_REVIEW = 'PENDING_REVIEW';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_RESUBMISSION_REQUIRED = 'RESUBMISSION_REQUIRED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'practitioner_profile_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PractitionerProfile $practitionerProfile;

    #[ORM\Column(length: 180)]
    private string $label;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_PENDING_REVIEW;

    #[ORM\Column(name: 'current_version_number', options: ['default' => 0])]
    private int $currentVersionNumber = 0;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    #[ORM\Column(name: 'updated_at_utc')]
    private \DateTimeImmutable $updatedAtUtc;

    public function __construct(PractitionerProfile $practitionerProfile, string $label)
    {
        $this->practitionerProfile = $practitionerProfile;
        $this->label = $label;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAtUtc = $now;
        $this->updatedAtUtc = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPractitionerProfile(): PractitionerProfile
    {
        return $this->practitionerProfile;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
        $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCurrentVersionNumber(): int
    {
        return $this->currentVersionNumber;
    }

    public function markPendingReview(int $versionNumber): void
    {
        $this->status = self::STATUS_PENDING_REVIEW;
        $this->currentVersionNumber = $versionNumber;
        $this->touch();
    }

    public function applyDecision(string $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getUpdatedAtUtc(): \DateTimeImmutable
    {
        return $this->updatedAtUtc;
    }

    private function touch(): void
    {
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
