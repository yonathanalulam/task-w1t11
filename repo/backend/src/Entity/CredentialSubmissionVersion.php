<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CredentialSubmissionVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CredentialSubmissionVersionRepository::class)]
#[ORM\Table(name: 'credential_submission_versions')]
class CredentialSubmissionVersion
{
    public const REVIEW_STATUS_PENDING_REVIEW = CredentialSubmission::STATUS_PENDING_REVIEW;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'submission_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CredentialSubmission $submission;

    #[ORM\Column(name: 'version_number')]
    private int $versionNumber;

    #[ORM\Column(name: 'storage_path', length: 255)]
    private string $storagePath;

    #[ORM\Column(name: 'original_filename', length: 255)]
    private string $originalFilename;

    #[ORM\Column(name: 'mime_type', length: 120)]
    private string $mimeType;

    #[ORM\Column(name: 'size_bytes')]
    private int $sizeBytes;

    #[ORM\Column(name: 'review_status', length: 32)]
    private string $reviewStatus = self::REVIEW_STATUS_PENDING_REVIEW;

    #[ORM\Column(name: 'review_comment', type: Types::TEXT, nullable: true)]
    private ?string $reviewComment = null;

    #[ORM\Column(name: 'reviewed_by_username', length: 180, nullable: true)]
    private ?string $reviewedByUsername = null;

    #[ORM\Column(name: 'reviewed_at_utc', nullable: true)]
    private ?\DateTimeImmutable $reviewedAtUtc = null;

    #[ORM\Column(name: 'uploaded_by_username', length: 180)]
    private string $uploadedByUsername;

    #[ORM\Column(name: 'uploaded_at_utc')]
    private \DateTimeImmutable $uploadedAtUtc;

    public function __construct(
        CredentialSubmission $submission,
        int $versionNumber,
        string $storagePath,
        string $originalFilename,
        string $mimeType,
        int $sizeBytes,
        string $uploadedByUsername,
    ) {
        $this->submission = $submission;
        $this->versionNumber = $versionNumber;
        $this->storagePath = $storagePath;
        $this->originalFilename = $originalFilename;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->uploadedByUsername = $uploadedByUsername;
        $this->uploadedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubmission(): CredentialSubmission
    {
        return $this->submission;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getReviewStatus(): string
    {
        return $this->reviewStatus;
    }

    public function getReviewComment(): ?string
    {
        return $this->reviewComment;
    }

    public function getReviewedByUsername(): ?string
    {
        return $this->reviewedByUsername;
    }

    public function getReviewedAtUtc(): ?\DateTimeImmutable
    {
        return $this->reviewedAtUtc;
    }

    public function getUploadedByUsername(): string
    {
        return $this->uploadedByUsername;
    }

    public function getUploadedAtUtc(): \DateTimeImmutable
    {
        return $this->uploadedAtUtc;
    }

    public function applyDecision(string $status, ?string $comment, string $reviewerUsername): void
    {
        $this->reviewStatus = $status;
        $this->reviewComment = $comment;
        $this->reviewedByUsername = $reviewerUsername;
        $this->reviewedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
