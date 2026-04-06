<?php

declare(strict_types=1);

namespace App\Message;

final readonly class AuditEventMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $actionType,
        public ?string $actorUsername,
        public array $payload = [],
    ) {
    }
}
