<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Real-HTTP coverage for the public-facing auth, permission, and health surface.
 *
 * Endpoints exercised:
 *   GET  /api/health/ready
 *   GET  /api/auth/captcha
 *   POST /api/auth/register
 *   GET  /api/permissions/me
 *
 * All other auth/session endpoints (login/logout/csrf-token/me) are covered by
 * ApiHttpSmokeTest.
 */
final class ApiAuthPermissionsHealthSmokeTest extends AbstractHttpSmokeTestCase
{
    public function testHealthReadyReportsDatabaseAndKeyringOverRealHttp(): void
    {
        $response = $this->request('GET', '/api/health/ready');
        self::assertSame(200, $response['status']);

        $payload = $this->json($response['body'])['data'] ?? [];
        self::assertSame('ready', $payload['status'] ?? null);
        self::assertSame('ok', $payload['database'] ?? null);
        self::assertArrayHasKey('activeKeyId', $payload['keyring'] ?? []);
        self::assertNotSame('', (string) ($payload['keyring']['activeKeyId'] ?? ''));
    }

    public function testCaptchaIssuesChallengeOnPublicRouteOverRealHttp(): void
    {
        $response = $this->request('GET', '/api/auth/captcha');
        self::assertSame(200, $response['status']);

        $data = $this->json($response['body'])['data'] ?? [];
        self::assertNotSame('', (string) ($data['challengeId'] ?? ''));
        self::assertNotSame('', (string) ($data['prompt'] ?? ''));
    }

    public function testRegisterCreatesStandardUserThenLoginSucceedsOverRealHttp(): void
    {
        $username = 'smoke_reg_' . bin2hex(random_bytes(4));
        $password = 'SmokeRegisterPass123!';

        $register = $this->request('POST', '/api/auth/register', [
            'json' => [
                'username' => $username,
                'password' => $password,
            ],
        ]);
        self::assertSame(201, $register['status'], 'register body: ' . $register['body']);
        $payload = $this->json($register['body'])['data'] ?? [];
        self::assertSame($username, $payload['username'] ?? null);
        self::assertContains('ROLE_STANDARD_USER', (array) ($payload['roles'] ?? []));

        $login = $this->request('POST', '/api/auth/login', [
            'json' => ['username' => $username, 'password' => $password],
        ]);
        self::assertSame(200, $login['status']);
    }

    public function testPermissionsMeReflectsRolePermissionsOverRealHttp(): void
    {
        $this->loginAs('analyst_user');

        $response = $this->request('GET', '/api/permissions/me');
        self::assertSame(200, $response['status']);

        $data = $this->json($response['body'])['data'] ?? [];
        self::assertSame('analyst_user', $data['username'] ?? null);
        self::assertContains('ROLE_ANALYST', (array) ($data['roles'] ?? []));
        self::assertContains('analytics.query', (array) ($data['permissions'] ?? []));
        self::assertContains('analytics.export', (array) ($data['permissions'] ?? []));
    }

    public function testPermissionsMeRejectsUnauthenticatedOverRealHttp(): void
    {
        $response = $this->request('GET', '/api/permissions/me');
        self::assertSame(401, $response['status']);
        self::assertSame('UNAUTHENTICATED', $this->json($response['body'])['error']['code'] ?? null);
    }
}
