<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public static function success(array $data, int $status = 200, ?string $requestId = null): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => ['requestId' => $requestId],
        ], $status);
    }

    /**
     * @param array<int, array<string, mixed>> $details
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        array $details = [],
        ?string $requestId = null,
    ): JsonResponse {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'meta' => ['requestId' => $requestId],
        ], $status);
    }
}
