<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuestionBankEntryVersionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuestionBankEntryVersionRepository::class)]
#[ORM\Table(name: 'question_bank_entry_versions')]
#[ORM\Index(name: 'idx_question_entry_version_lookup', columns: ['entry_id', 'version_number'])]
class QuestionBankEntryVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'entry_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private QuestionBankEntry $entry;

    #[ORM\Column(name: 'version_number')]
    private int $versionNumber;

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

    #[ORM\Column(name: 'created_by_username', length: 180)]
    private string $createdByUsername;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    #[ORM\Column(name: 'change_note', type: 'text', nullable: true)]
    private ?string $changeNote;

    public function __construct(QuestionBankEntry $entry, int $versionNumber, ?string $changeNote, string $createdByUsername)
    {
        $this->entry = $entry;
        $this->versionNumber = $versionNumber;
        $this->title = $entry->getTitle();
        $this->plainTextContent = $entry->getPlainTextContent();
        $this->richTextContent = $entry->getRichTextContent();
        $this->tags = $entry->getTags();
        $this->difficulty = $entry->getDifficulty();
        $this->formulas = $entry->getFormulas();
        $this->embeddedImages = $entry->getEmbeddedImages();
        $this->changeNote = $changeNote;
        $this->createdByUsername = $createdByUsername;
        $this->createdAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
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

    public function getCreatedByUsername(): string
    {
        return $this->createdByUsername;
    }

    public function getCreatedAtUtc(): \DateTimeImmutable
    {
        return $this->createdAtUtc;
    }

    public function getChangeNote(): ?string
    {
        return $this->changeNote;
    }
}
