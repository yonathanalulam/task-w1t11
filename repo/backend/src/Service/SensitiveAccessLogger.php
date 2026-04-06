<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SensitiveAccessLog;
use Doctrine\ORM\EntityManagerInterface;

final class SensitiveAccessLogger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function log(
        string $actorUsername,
        string $entityType,
        string $entityId,
        string $fieldName,
        string $reason,
    ): void {
        $entry = new SensitiveAccessLog($actorUsername, $entityType, $entityId, $fieldName, $reason);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }
}
