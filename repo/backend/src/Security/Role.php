<?php

declare(strict_types=1);

namespace App\Security;

final class Role
{
    public const STANDARD_USER = 'ROLE_STANDARD_USER';
    public const CONTENT_ADMIN = 'ROLE_CONTENT_ADMIN';
    public const CREDENTIAL_REVIEWER = 'ROLE_CREDENTIAL_REVIEWER';
    public const ANALYST = 'ROLE_ANALYST';
    public const SYSTEM_ADMIN = 'ROLE_SYSTEM_ADMIN';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::STANDARD_USER,
            self::CONTENT_ADMIN,
            self::CREDENTIAL_REVIEWER,
            self::ANALYST,
            self::SYSTEM_ADMIN,
        ];
    }
}
