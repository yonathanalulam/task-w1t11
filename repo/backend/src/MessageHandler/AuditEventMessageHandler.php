<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AuditEventMessage;
use App\Service\AuditLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AuditEventMessageHandler
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function __invoke(AuditEventMessage $message): void
    {
        $this->auditLogger->log($message->actionType, $message->actorUsername, $message->payload);
    }
}
