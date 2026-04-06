<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuestionBankAssetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuestionBankAssetRepository::class)]
#[ORM\Table(name: 'question_bank_assets')]
class QuestionBankAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'storage_path', length: 255)]
    private string $storagePath;

    #[ORM\Column(name: 'original_filename', length: 255)]
    private string $originalFilename;

    #[ORM\Column(name: 'mime_type', length: 120)]
    private string $mimeType;

    #[ORM\Column(name: 'size_bytes')]
    private int $sizeBytes;

    #[ORM\Column(name: 'uploaded_by_username', length: 180)]
    private string $uploadedByUsername;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    public function __construct(string $storagePath, string $originalFilename, string $mimeType, int $sizeBytes, string $uploadedByUsername)
    {
        $this->storagePath = $storagePath;
        $this->originalFilename = $originalFilename;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->uploadedByUsername = $uploadedByUsername;
        $this->createdAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getUploadedByUsername(): string
    {
        return $this->uploadedByUsername;
    }

    public function getCreatedAtUtc(): \DateTimeImmutable
    {
        return $this->createdAtUtc;
    }
}
