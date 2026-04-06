<?php

declare(strict_types=1);

namespace App\Security;

final class ApiRouteAccessPolicy
{
    /** @var list<string> */
    private array $publicPaths = [
        '/api/health/live',
        '/api/health/ready',
        '/api/auth/login',
        '/api/auth/register',
        '/api/auth/captcha',
        '/api/auth/csrf-token',
    ];

    public function isApiPath(string $path): bool
    {
        return str_starts_with($path, '/api/');
    }

    public function isPublicPath(string $path): bool
    {
        return in_array($path, $this->publicPaths, true);
    }
}
