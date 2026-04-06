<?php

declare(strict_types=1);

namespace App\Exception;

final class QuestionBankFlowException extends \RuntimeException
{
    /** @var array<int, array<string, mixed>> */
    private array $details;

    public function __construct(
        private readonly string $apiCode,
        private readonly int $httpStatus,
        string $message,
        array $details = [],
    ) {
        parent::__construct($message);
        $this->details = $details;
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
