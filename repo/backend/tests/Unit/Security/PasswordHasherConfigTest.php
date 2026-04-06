<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use Symfony\Component\Yaml\Yaml;
use PHPUnit\Framework\TestCase;

final class PasswordHasherConfigTest extends TestCase
{
    public function testBcryptIsConfiguredExplicitly(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3).'/config/packages/security.yaml');

        self::assertIsArray($config);
        self::assertSame(
            'bcrypt',
            $config['security']['password_hashers']['App\\Entity\\User']['algorithm'] ?? null,
        );
    }
}
