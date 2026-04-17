<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Core real-HTTP smoke layer against the composed Docker API.
 *
 * Focus: auth/session/CSRF/cookie lifecycle and one file-download path.
 * Broader endpoint-family coverage lives in ApiWorkflowSmokeTest.
 *
 * Enabled when SMOKE_BASE_URL is set (e.g. http://api:8000). Skipped
 * otherwise so `php bin/phpunit` without the stack is still usable.
 *
 * Run via: scripts/dev/real_http_smoke.sh
 */
final class ApiHttpSmokeTest extends AbstractHttpSmokeTestCase
{
    public function testHealthLiveReturnsTwoHundredOverRealHttp(): void
    {
        $response = $this->request('GET', '/api/health/live');
        self::assertSame(200, $response['status']);
    }

    public function testSessionLifecycleWithRealCookieAndCsrfRoundtrip(): void
    {
        $unauthed = $this->request('GET', '/api/auth/me');
        self::assertSame(401, $unauthed['status']);
        self::assertSame('UNAUTHENTICATED', $this->json($unauthed['body'])['error']['code'] ?? null);

        $login = $this->request('POST', '/api/auth/login', [
            'json' => [
                'username' => 'standard_user',
                'password' => $this->devPassword(),
            ],
        ]);
        self::assertSame(200, $login['status'], 'login should succeed; got body: ' . $login['body']);
        self::assertNotEmpty(
            $this->cookieJar,
            'Login must return at least one Set-Cookie header for session continuity.',
        );

        $me = $this->request('GET', '/api/auth/me');
        self::assertSame(200, $me['status']);
        self::assertSame('standard_user', $this->json($me['body'])['data']['username'] ?? null);

        $logoutWithoutCsrf = $this->request('POST', '/api/auth/logout', [
            'json' => [],
        ]);
        self::assertSame(403, $logoutWithoutCsrf['status']);
        self::assertSame('ACCESS_DENIED', $this->json($logoutWithoutCsrf['body'])['error']['code'] ?? null);

        $csrfResponse = $this->request('GET', '/api/auth/csrf-token');
        self::assertSame(200, $csrfResponse['status']);
        $csrfToken = (string) ($this->json($csrfResponse['body'])['data']['csrfToken'] ?? '');
        self::assertNotSame('', $csrfToken, 'CSRF token must be issued on the active session.');

        $logout = $this->request('POST', '/api/auth/logout', [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $csrfToken],
        ]);
        self::assertSame(200, $logout['status']);

        $afterLogout = $this->request('GET', '/api/auth/me');
        self::assertSame(401, $afterLogout['status']);
    }

    public function testAnalystCsvExportDeliversAttachmentOverRealHttp(): void
    {
        $csrfToken = $this->loginAs('analyst_user');

        $export = $this->request('POST', '/api/analytics/query/export', [
            'json' => [
                'fromDate' => '2026-01-01',
                'toDate' => '2026-12-31',
                'orgUnits' => [],
                'featureIds' => [],
                'datasetIds' => [],
                'includeLiveData' => true,
            ],
            'headers' => ['X-CSRF-Token' => $csrfToken],
        ]);

        self::assertSame(200, $export['status']);
        self::assertStringContainsString('text/csv', strtolower($export['headers']['content-type'] ?? ''));
        self::assertStringContainsString('attachment;', strtolower($export['headers']['content-disposition'] ?? ''));
        self::assertStringContainsString('occurredAtUtc,orgUnit,source', $export['body']);
    }
}
