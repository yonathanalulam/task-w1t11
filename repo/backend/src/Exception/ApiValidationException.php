<?php

declare(strict_types=1);

namespace App\Exception;

final class ApiValidationException extends \RuntimeException
{
    /** @var array<int, array<string, mixed>> */
    private array $details;

    /**
     * @param array<int, array<string, mixed>> $details
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    /** @return array<int, array<string, mixed>> */
    public function details(): array
    {
        return $this->details;
    }
}
