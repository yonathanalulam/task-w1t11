<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Real-HTTP coverage for governance + admin integrations surfaces.
 *
 * Endpoints exercised:
 *   GET  /api/admin/governance/sensitive-access-logs
 *   POST /api/admin/governance/sensitive/practitioner-profiles/{profileId}/license
 *   GET  /api/admin/governance/anomalies
 *   POST /api/admin/governance/anomalies/refresh
 *   POST /api/admin/governance/anomalies/{alertId}/acknowledge
 *   GET  /api/admin/governance/rollback/credential-submissions
 *   GET  /api/admin/governance/rollback/question-entries
 *   POST /api/admin/governance/rollback/credentials
 *   POST /api/admin/governance/rollback/questions
 *   POST /api/admin/governance/users/password-reset
 *   GET  /api/admin/integrations/human-verification
 */
final class ApiGovernanceFullSmokeTest extends AbstractHttpSmokeTestCase
{
    public function testGovernanceReadPanelsReturnEnvelopesOverRealHttp(): void
    {
        $this->loginAs('system_admin');

        $sensitive = $this->request('GET', '/api/admin/governance/sensitive-access-logs?sinceHours=24&limit=10');
        self::assertSame(200, $sensitive['status']);
        $sensitiveData = $this->json($sensitive['body'])['data'] ?? [];
        self::assertIsArray($sensitiveData['logs'] ?? null);
        self::assertSame(7, (int) ($sensitiveData['retentionPolicy']['minimumRetentionYears'] ?? 0));

        $anomalies = $this->request('GET', '/api/admin/governance/anomalies');
        self::assertSame(200, $anomalies['status']);
        $anomaliesData = $this->json($anomalies['body'])['data'] ?? [];
        self::assertSame('OPEN', $anomaliesData['statusFilter'] ?? null);
        self::assertIsArray($anomaliesData['alerts'] ?? null);

        $rollbackCreds = $this->request('GET', '/api/admin/governance/rollback/credential-submissions');
        self::assertSame(200, $rollbackCreds['status']);
        self::assertIsArray($this->json($rollbackCreds['body'])['data']['submissions'] ?? null);

        $rollbackQuestions = $this->request('GET', '/api/admin/governance/rollback/question-entries');
        self::assertSame(200, $rollbackQuestions['status']);
        self::assertIsArray($this->json($rollbackQuestions['body'])['data']['entries'] ?? null);
    }

    public function testSensitiveLicenseReadForSeededProfileOverRealHttp(): void
    {
        // Seed a profile as standard_user so admin has a concrete target.
        $userCsrf = $this->loginAs('standard_user');
        $suffix = bin2hex(random_bytes(3));
        $upsert = $this->request('PUT', '/api/practitioner/profile', [
            'json' => [
                'lawyerFullName' => 'Gov Smoke ' . $suffix,
                'firmName' => 'Gov Smoke Firm',
                'barJurisdiction' => 'NY',
                'licenseNumber' => 'NY-GOV-' . $suffix,
            ],
            'headers' => ['X-CSRF-Token' => $userCsrf],
        ]);
        self::assertSame(200, $upsert['status']);
        $profileId = (int) ($this->json($upsert['body'])['data']['profile']['id'] ?? 0);
        self::assertGreaterThan(0, $profileId);

        $this->cookieJar = [];
        $adminCsrf = $this->loginAs('system_admin');

        $read = $this->request('POST', sprintf('/api/admin/governance/sensitive/practitioner-profiles/%d/license', $profileId), [
            'json' => ['reason' => 'Operational review for smoke-coverage governance evidence.'],
            'headers' => ['X-CSRF-Token' => $adminCsrf],
        ]);
        self::assertSame(200, $read['status'], 'sensitive read body: ' . $read['body']);
        self::assertSame('NY-GOV-' . $suffix, $this->json($read['body'])['data']['licenseNumber'] ?? null);
    }

    public function testAnomalyRefreshAndAcknowledgePathOverRealHttp(): void
    {
        $csrf = $this->loginAs('system_admin');

        $refresh = $this->request('POST', '/api/admin/governance/anomalies/refresh', [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(200, $refresh['status'], 'anomaly refresh body: ' . $refresh['body']);
        $alerts = $this->json($refresh['body'])['data']['alerts'] ?? [];
        self::assertIsArray($alerts);

        // Acknowledge path: hit the endpoint with a non-existent alertId to exercise
        // the route regardless of whether the current DB happens to surface alerts.
        $ack = $this->request('POST', '/api/admin/governance/anomalies/9999999/acknowledge', [
            'json' => ['note' => 'Smoke coverage ack attempt — expected not-found for synthetic alert id.'],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(404, $ack['status']);
        self::assertSame('NOT_FOUND', $this->json($ack['body'])['error']['code'] ?? null);
    }

    public function testRollbackEndpointsRejectUnknownTargetsOverRealHttp(): void
    {
        $csrf = $this->loginAs('system_admin');

        $credsRollback = $this->request('POST', '/api/admin/governance/rollback/credentials', [
            'json' => [
                'submissionId' => 9999999,
                'targetVersionNumber' => 1,
                'stepUpPassword' => $this->devPassword(),
                'justificationNote' => 'Smoke coverage: hitting rollback endpoint against synthetic id.',
            ],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(404, $credsRollback['status']);
        self::assertSame('NOT_FOUND', $this->json($credsRollback['body'])['error']['code'] ?? null);

        $questionRollback = $this->request('POST', '/api/admin/governance/rollback/questions', [
            'json' => [
                'entryId' => 9999999,
                'targetVersionNumber' => 1,
                'stepUpPassword' => $this->devPassword(),
                'justificationNote' => 'Smoke coverage: hitting rollback endpoint against synthetic id.',
            ],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(404, $questionRollback['status']);
        self::assertSame('NOT_FOUND', $this->json($questionRollback['body'])['error']['code'] ?? null);
    }

    public function testAdminPasswordResetHappyPathOverRealHttp(): void
    {
        // 1. Register a fresh throwaway user so we have a definite reset target.
        $targetUsername = 'smoke_reset_' . bin2hex(random_bytes(4));
        $originalPassword = 'SmokeOriginalPassword123!';
        $resetPassword = 'SmokeResetPassword123!';

        $register = $this->request('POST', '/api/auth/register', [
            'json' => ['username' => $targetUsername, 'password' => $originalPassword],
        ]);
        self::assertSame(201, $register['status']);

        // 2. Admin performs the reset via governance endpoint.
        $this->cookieJar = [];
        $adminCsrf = $this->loginAs('system_admin');
        $reset = $this->request('POST', '/api/admin/governance/users/password-reset', [
            'json' => [
                'targetUsername' => $targetUsername,
                'newPassword' => $resetPassword,
                'stepUpPassword' => $this->devPassword(),
                'justificationNote' => 'Smoke-coverage admin reset for governance password-reset endpoint.',
            ],
            'headers' => ['X-CSRF-Token' => $adminCsrf],
        ]);
        self::assertSame(200, $reset['status'], 'password reset body: ' . $reset['body']);
        self::assertSame('PASSWORD_RESET', $this->json($reset['body'])['data']['status'] ?? null);
        self::assertSame($targetUsername, $this->json($reset['body'])['data']['targetUsername'] ?? null);

        // 3. New password works.
        $this->cookieJar = [];
        $newLogin = $this->request('POST', '/api/auth/login', [
            'json' => ['username' => $targetUsername, 'password' => $resetPassword],
        ]);
        self::assertSame(200, $newLogin['status']);
    }

    public function testAdminHumanVerificationStatusOverRealHttp(): void
    {
        $this->loginAs('system_admin');

        $response = $this->request('GET', '/api/admin/integrations/human-verification');
        self::assertSame(200, $response['status']);
        $data = $this->json($response['body'])['data'] ?? [];
        self::assertSame('DISABLED', $data['status'] ?? null);
        self::assertFalse((bool) ($data['networkDependencyRequired'] ?? true));
    }
}
