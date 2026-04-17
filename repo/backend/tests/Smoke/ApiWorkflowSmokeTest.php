<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Broader real-HTTP workflow coverage: one endpoint per critical family.
 *
 * Each case hits a different role to ensure role-scoped permission gates
 * behave correctly over real HTTP, not just kernel-internal createClient().
 *
 * Families covered:
 *   - practitioner profile (standard_user)
 *   - practitioner credential listing (standard_user)
 *   - reviewer queue (credential_reviewer)
 *   - scheduling configuration (system_admin)
 *   - question-bank catalog (content_admin)
 *   - governance audit-logs read (system_admin)
 *
 * Skipped when SMOKE_BASE_URL is unset. Run via scripts/dev/real_http_smoke.sh.
 */
final class ApiWorkflowSmokeTest extends AbstractHttpSmokeTestCase
{
    public function testPractitionerProfileUpsertAndReadRoundtripOverRealHttp(): void
    {
        $csrfToken = $this->loginAs('standard_user');

        $suffix = bin2hex(random_bytes(3));
        $upsert = $this->request('PUT', '/api/practitioner/profile', [
            'json' => [
                'lawyerFullName' => 'Smoke Coverage Lawyer',
                'firmName' => 'Smoke Coverage Firm',
                'barJurisdiction' => 'CA',
                'licenseNumber' => 'SMK-' . $suffix,
            ],
            'headers' => ['X-CSRF-Token' => $csrfToken],
        ]);

        self::assertSame(200, $upsert['status'], 'profile upsert body: ' . $upsert['body']);
        $upserted = $this->json($upsert['body'])['data']['profile'] ?? [];
        self::assertSame('Smoke Coverage Lawyer', $upserted['lawyerFullName'] ?? null);
        self::assertNotSame('SMK-' . $suffix, $upserted['licenseNumberMasked'] ?? null, 'license number must be masked in response');

        $read = $this->request('GET', '/api/practitioner/profile');
        self::assertSame(200, $read['status']);
        $profile = $this->json($read['body'])['data']['profile'] ?? [];
        self::assertSame('Smoke Coverage Lawyer', $profile['lawyerFullName'] ?? null);
        self::assertSame('CA', $profile['barJurisdiction'] ?? null);
    }

    public function testPractitionerCredentialListReturnsEnvelopeOverRealHttp(): void
    {
        $this->loginAs('standard_user');

        $list = $this->request('GET', '/api/practitioner/credentials');
        self::assertSame(200, $list['status'], 'credentials list body: ' . $list['body']);

        $data = $this->json($list['body'])['data'] ?? [];
        self::assertArrayHasKey('profileRequired', $data);
        self::assertIsBool($data['profileRequired']);
        self::assertArrayHasKey('submissions', $data);
        self::assertIsArray($data['submissions']);
    }

    public function testReviewerQueueReturnsEnvelopeForReviewerRoleOverRealHttp(): void
    {
        $this->loginAs('credential_reviewer');

        $queue = $this->request('GET', '/api/reviewer/credentials/queue');
        self::assertSame(200, $queue['status'], 'reviewer queue body: ' . $queue['body']);

        $data = $this->json($queue['body'])['data'] ?? [];
        self::assertSame('PENDING_REVIEW', $data['statusFilter'] ?? null);
        self::assertIsArray($data['queue'] ?? null);
    }

    public function testReviewerQueueIsForbiddenForStandardUserOverRealHttp(): void
    {
        $this->loginAs('standard_user');

        $forbidden = $this->request('GET', '/api/reviewer/credentials/queue');
        self::assertSame(403, $forbidden['status']);
        self::assertSame('ACCESS_DENIED', $this->json($forbidden['body'])['error']['code'] ?? null);
    }

    public function testSchedulingConfigurationRoundtripForAdminOverRealHttp(): void
    {
        $csrfToken = $this->loginAs('system_admin');

        $suffix = bin2hex(random_bytes(3));
        $put = $this->request('PUT', '/api/scheduling/configuration', [
            'json' => [
                'practitionerName' => 'Smoke Practitioner ' . $suffix,
                'locationName' => 'Smoke Room ' . $suffix,
                'slotDurationMinutes' => 30,
                'slotCapacity' => 1,
                'weeklyAvailability' => [
                    ['weekday' => 1, 'startTime' => '09:00', 'endTime' => '11:00'],
                ],
            ],
            'headers' => ['X-CSRF-Token' => $csrfToken],
        ]);
        self::assertSame(200, $put['status'], 'scheduling PUT body: ' . $put['body']);

        $get = $this->request('GET', '/api/scheduling/configuration');
        self::assertSame(200, $get['status']);
        $config = $this->json($get['body'])['data']['configuration'] ?? [];
        self::assertSame('Smoke Practitioner ' . $suffix, $config['practitionerName'] ?? null);
        self::assertSame(30, $config['slotDurationMinutes'] ?? null);
    }

    public function testQuestionBankListIsVisibleToContentAdminOverRealHttp(): void
    {
        $this->loginAs('content_admin');

        $list = $this->request('GET', '/api/question-bank/questions');
        self::assertSame(200, $list['status'], 'question bank list body: ' . $list['body']);

        $data = $this->json($list['body'])['data'] ?? [];
        self::assertArrayHasKey('entries', $data);
        self::assertIsArray($data['entries']);
    }

    public function testGovernanceAuditLogsReadableBySystemAdminOverRealHttp(): void
    {
        $this->loginAs('system_admin');

        $logs = $this->request('GET', '/api/admin/governance/audit-logs?sinceHours=24&limit=10');
        self::assertSame(200, $logs['status'], 'audit logs body: ' . $logs['body']);

        $data = $this->json($logs['body'])['data'] ?? [];
        self::assertTrue((bool) ($data['immutable'] ?? false));
        self::assertIsArray($data['logs'] ?? null);
        self::assertSame(
            7,
            (int) ($data['retentionPolicy']['minimumRetentionYears'] ?? 0),
            'retention policy must expose the 7-year minimum floor in governance read endpoints',
        );
    }
}
