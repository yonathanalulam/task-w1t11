<?php

declare(strict_types=1);

namespace App\Service;

final class LicenseNumberMasker
{
    public function mask(string $licenseNumber): string
    {
        $normalized = preg_replace('/\s+/', '', trim($licenseNumber));
        if (!is_string($normalized) || $normalized === '') {
            return '****';
        }

        $suffix = substr($normalized, -4);
        $prefixLength = max(0, strlen($normalized) - strlen($suffix));
        $prefix = str_repeat('•', max(4, $prefixLength));

        return $prefix.$suffix;
    }
}
