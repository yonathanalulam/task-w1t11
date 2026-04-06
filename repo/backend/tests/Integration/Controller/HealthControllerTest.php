<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testLiveEndpointReturnsSuccess(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health/live');

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');
    }

    public function testReadyEndpointReturnsDependencyStatusWhenHealthy(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health/ready');

        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame('ready', $payload['data']['status'] ?? null);
        self::assertSame('ok', $payload['data']['database'] ?? null);
        self::assertNotSame('', (string) ($payload['data']['keyring']['activeKeyId'] ?? ''));
    }

    public function testReadyEndpointReturnsNotReadyWhenKeyringIsInvalid(): void
    {
        $keyringPath = getenv('FIELD_ENCRYPTION_KEYRING_PATH');
        self::assertIsString($keyringPath);
        self::assertNotSame('', $keyringPath);
        self::assertFileExists($keyringPath);

        if (!is_writable($keyringPath)) {
            self::markTestSkipped('Keyring path is not writable for readiness failure simulation.');
        }

        $original = file_get_contents($keyringPath);
        self::assertIsString($original);

        try {
            file_put_contents($keyringPath, '{"activeKeyId":"missing","keys":{}}');

            $client = static::createClient();
            $client->request('GET', '/api/health/ready');

            self::assertResponseStatusCodeSame(503);
            $payload = $this->json($client->getResponse()->getContent());
            self::assertSame('NOT_READY', $payload['error']['code'] ?? null);
        } finally {
            file_put_contents($keyringPath, $original);
        }
    }

    /** @return array<string, mixed> */
    private function json(string|false $content): array
    {
        return is_string($content) ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
