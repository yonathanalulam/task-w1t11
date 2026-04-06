<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function log(string $actionType, ?string $actorUsername, array $payload = []): void
    {
        $entry = new AuditLog($actionType, $actorUsername, $payload);

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $this->auditLogger->info($actionType, [
            'actor' => $actorUsername,
            'payload' => $payload,
        ]);
    }
}
