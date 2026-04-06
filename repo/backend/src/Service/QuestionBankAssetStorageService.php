<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class QuestionBankAssetStorageService
{
    public function __construct(private readonly string $storageRoot)
    {
    }

    /** @return array{storagePath: string, originalFilename: string, mimeType: string, sizeBytes: int} */
    public function storeUploadedFile(UploadedFile $file): array
    {
        $datePath = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y/m/d');
        $targetDirectory = rtrim($this->storageRoot, '/').'/'.$datePath;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0770, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Unable to create question-asset storage directory.');
        }

        $originalFilename = trim($file->getClientOriginalName());
        $originalFilename = $originalFilename !== '' ? $originalFilename : 'question-asset';

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'bin';
        }

        $targetName = bin2hex(random_bytes(16)).'.'.$extension;
        $file->move($targetDirectory, $targetName);

        $absolutePath = $targetDirectory.'/'.$targetName;
        if (!is_file($absolutePath)) {
            throw new \RuntimeException('Question-bank asset file was not persisted.');
        }

        $size = filesize($absolutePath);

        return [
            'storagePath' => $datePath.'/'.$targetName,
            'originalFilename' => $originalFilename,
            'mimeType' => (string) ($file->getClientMimeType() ?: 'application/octet-stream'),
            'sizeBytes' => is_int($size) ? $size : (int) ($file->getSize() ?? 0),
        ];
    }

    public function resolveAbsolutePath(string $storagePath): string
    {
        $normalized = ltrim($storagePath, '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            throw new \RuntimeException('Question-bank asset storage path is invalid.');
        }

        $absolute = rtrim($this->storageRoot, '/').'/'.$normalized;
        if (!is_file($absolute)) {
            throw new \RuntimeException('Question-bank asset not found in storage.');
        }

        return $absolute;
    }
}
