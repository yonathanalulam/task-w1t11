<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiAuthBoundaryTest extends WebTestCase
{
    public function testPublicApiRoutesRemainAccessibleWithoutAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/health/live');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/auth/csrf-token');
        self::assertResponseIsSuccessful();
        self::assertSame('X-CSRF-Token', $this->json($client->getResponse()->getContent())['data']['headerName'] ?? null);
    }

    public function testProtectedApiRouteRejectsAnonymousByDefault(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/permissions/me');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('UNAUTHENTICATED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
    }

    public function testProtectedRouteSucceedsAfterSessionLogin(): void
    {
        $client = static::createClient();
        $password = getenv('DEV_BOOTSTRAP_PASSWORD');
        self::assertIsString($password);
        self::assertNotSame('', $password);

        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => 'system_admin',
            'password' => $password,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/permissions/me');
        self::assertResponseIsSuccessful();
        self::assertSame('system_admin', $this->json($client->getResponse()->getContent())['data']['username'] ?? null);
    }

    /** @return array<string, mixed> */
    private function json(string|false $content): array
    {
        return is_string($content) ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
