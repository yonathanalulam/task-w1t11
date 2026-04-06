<?php

declare(strict_types=1);

namespace App\Exception;

final class SchedulingFlowException extends \RuntimeException
{
    /** @param array<int, array<string, mixed>> $details */
    public function __construct(
        private readonly string $apiCode,
        private readonly int $httpStatus,
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<int, array<string, mixed>> */
    public function details(): array
    {
        return $this->details;
    }
}
