<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\KeyringProvider;
use PHPUnit\Framework\TestCase;

final class KeyringProviderTest extends TestCase
{
    public function testLoadsActiveKeyFromLocalKeyring(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'keyring_');
        self::assertIsString($tmp);

        file_put_contents($tmp, json_encode([
            'activeKeyId' => 'k1',
            'keys' => ['k1' => base64_encode(random_bytes(32))],
        ], JSON_THROW_ON_ERROR));

        $provider = new KeyringProvider($tmp);
        $active = $provider->activeKey();

        self::assertSame('k1', $active['keyId']);

        @unlink($tmp);
    }
}
