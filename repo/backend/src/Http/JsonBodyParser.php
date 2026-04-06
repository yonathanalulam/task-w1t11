<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ApiValidationException;
use Symfony\Component\HttpFoundation\Request;

final class JsonBodyParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(Request $request): array
    {
        $raw = $request->getContent();
        if ($raw === '' || $raw === 'null') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiValidationException('Malformed JSON payload.');
        }

        if (!is_array($decoded)) {
            throw new ApiValidationException('JSON payload must be an object.');
        }

        return $decoded;
    }
}
