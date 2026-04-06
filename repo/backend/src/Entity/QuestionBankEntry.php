<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuestionBankEntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuestionBankEntryRepository::class)]
#[ORM\Table(name: 'question_bank_entries')]
class QuestionBankEntry
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PUBLISHED = 'PUBLISHED';
    public const STATUS_OFFLINE = 'OFFLINE';

    public const DUPLICATE_REVIEW_NONE = 'NONE';
    public const DUPLICATE_REVIEW_REQUIRED = 'REQUIRES_REVIEW';
    public const DUPLICATE_REVIEW_OVERRIDDEN = 'OVERRIDDEN';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 240)]
    private string $title;

    #[ORM\Column(name: 'plain_text_content', type: 'text')]
    private string $plainTextContent;

    #[ORM\Column(name: 'rich_text_content', type: 'text')]
    private string $richTextContent;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $tags;

    #[ORM\Column]
    private int $difficulty;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $formulas;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(name: 'embedded_images', type: 'json')]
    private array $embeddedImages;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'duplicate_review_state', length: 32)]
    private string $duplicateReviewState = self::DUPLICATE_REVIEW_NONE;

    #[ORM\Column(name: 'current_version_number')]
    private int $currentVersionNumber = 1;

    #[ORM\Column(name: 'created_by_username', length: 180)]
    private string $createdByUsername;

    #[ORM\Column(name: 'updated_by_username', length: 180)]
    private string $updatedByUsername;

    #[ORM\Column(name: 'published_by_username', length: 180, nullable: true)]
    private ?string $publishedByUsername = null;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    #[ORM\Column(name: 'updated_at_utc')]
    private \DateTimeImmutable $updatedAtUtc;

    #[ORM\Column(name: 'published_at_utc', nullable: true)]
    private ?\DateTimeImmutable $publishedAtUtc = null;

    #[ORM\Column(name: 'offline_at_utc', nullable: true)]
    private ?\DateTimeImmutable $offlineAtUtc = null;

    /**
     * @param list<string> $tags
     * @param list<array<string, mixed>> $formulas
     * @param list<array<string, mixed>> $embeddedImages
     */
    public function __construct(
        string $title,
        string $plainTextContent,
        string $richTextContent,
        array $tags,
        int $difficulty,
        array $formulas,
        array $embeddedImages,
        string $actorUsername,
    ) {
        $this->title = $title;
        $this->plainTextContent = $plainTextContent;
        $this->richTextContent = $richTextContent;
        $this->tags = $tags;
        $this->difficulty = $difficulty;
        $this->formulas = $formulas;
        $this->embeddedImages = $embeddedImages;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPlainTextContent(): string
    {
        return $this->plainTextContent;
    }

    public function getRichTextContent(): string
    {
        return $this->richTextContent;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getDifficulty(): int
    {
        return $this->difficulty;
    }

    /** @return list<array<string, mixed>> */
    public function getFormulas(): array
    {
        return $this->formulas;
    }

    /** @return list<array<string, mixed>> */
    public function getEmbeddedImages(): array
    {
        return $this->embeddedImages;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDuplicateReviewState(): string
    {
        return $this->duplicateReviewState;
    }

    public function getCurrentVersionNumber(): int
    {
        return $this->currentVersionNumber;
    }

    public function getUpdatedAtUtc(): \DateTimeImmutable
    {
        return $this->updatedAtUtc;
    }

    public function getCreatedAtUtc(): \DateTimeImmutable
    {
        return $this->createdAtUtc;
    }

    public function getPublishedAtUtc(): ?\DateTimeImmutable
    {
        return $this->publishedAtUtc;
    }

    public function getPublishedByUsername(): ?string
    {
        return $this->publishedByUsername;
    }

    public function getUpdatedByUsername(): string
    {
        return $this->updatedByUsername;
    }

    /**
     * @param list<string> $tags
     * @param list<array<string, mixed>> $formulas
     * @param list<array<string, mixed>> $embeddedImages
     */
    public function edit(
        string $title,
        string $plainTextContent,
        string $richTextContent,
        array $tags,
        int $difficulty,
        array $formulas,
        array $embeddedImages,
        string $actorUsername,
    ): void {
        $this->title = $title;
        $this->plainTextContent = $plainTextContent;
        $this->richTextContent = $richTextContent;
        $this->tags = $tags;
        $this->difficulty = $difficulty;
        $this->formulas = $formulas;
        $this->embeddedImages = $embeddedImages;
        $this->status = self::STATUS_DRAFT;
        $this->duplicateReviewState = self::DUPLICATE_REVIEW_NONE;
        ++$this->currentVersionNumber;
        $this->updatedByUsername = $actorUsername;
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markDuplicateReviewRequired(string $actorUsername): void
    {
        $this->duplicateReviewState = self::DUPLICATE_REVIEW_REQUIRED;
        $this->updatedByUsername = $actorUsername;
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function publish(string $actorUsername, bool $duplicateOverride): void
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->duplicateReviewState = $duplicateOverride ? self::DUPLICATE_REVIEW_OVERRIDDEN : self::DUPLICATE_REVIEW_NONE;
        $this->publishedByUsername = $actorUsername;
        $this->publishedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->offlineAtUtc = null;
        $this->updatedByUsername = $actorUsername;
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function offline(string $actorUsername): void
    {
        $this->status = self::STATUS_OFFLINE;
        $this->offlineAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedByUsername = $actorUsername;
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function rollbackContent(
        string $title,
        string $plainTextContent,
        string $richTextContent,
        array $tags,
        int $difficulty,
        array $formulas,
        array $embeddedImages,
        string $actorUsername,
    ): void {
        $this->title = $title;
        $this->plainTextContent = $plainTextContent;
        $this->richTextContent = $richTextContent;
        $this->tags = $tags;
        $this->difficulty = $difficulty;
        $this->formulas = $formulas;
        $this->embeddedImages = $embeddedImages;
        ++$this->currentVersionNumber;
        $this->duplicateReviewState = self::DUPLICATE_REVIEW_OVERRIDDEN;
        $this->updatedByUsername = $actorUsername;
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
