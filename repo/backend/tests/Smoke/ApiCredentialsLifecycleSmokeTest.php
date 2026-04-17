<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Real-HTTP credential lifecycle smoke tests: upload → reviewer decision → download.
 *
 * Endpoints exercised:
 *   POST /api/practitioner/credentials                             (multipart upload)
 *   POST /api/practitioner/credentials/{submissionId}/resubmit     (multipart upload)
 *   GET  /api/reviewer/credentials/{submissionId}
 *   POST /api/reviewer/credentials/{submissionId}/decision
 *   GET  /api/credentials/versions/{versionId}/download
 */
final class ApiCredentialsLifecycleSmokeTest extends AbstractHttpSmokeTestCase
{
    public function testCredentialUploadReviewDecisionAndDownloadOverRealHttp(): void
    {
        $suffix = bin2hex(random_bytes(3));

        // ---- standard_user: profile upsert then credential upload ----
        $userCsrf = $this->loginAs('standard_user');
        $this->request('PUT', '/api/practitioner/profile', [
            'json' => [
                'lawyerFullName' => 'Lifecycle Lawyer ' . $suffix,
                'firmName' => 'Lifecycle Firm',
                'barJurisdiction' => 'CA',
                'licenseNumber' => 'LC-' . $suffix,
            ],
            'headers' => ['X-CSRF-Token' => $userCsrf],
        ]);

        $upload = $this->requestMultipart(
            'POST',
            '/api/practitioner/credentials',
            ['label' => 'Smoke Credential ' . $suffix],
            [
                'file' => [
                    'filename' => 'smoke-credential.pdf',
                    'contentType' => 'application/pdf',
                    'content' => "%PDF-1.4\nSmoke credential bytes " . $suffix . "\n%%EOF",
                ],
            ],
            ['X-CSRF-Token' => $userCsrf],
        );
        self::assertSame(201, $upload['status'], 'upload body: ' . $upload['body']);
        $submission = $this->json($upload['body'])['data']['submission'] ?? [];
        self::assertSame('PENDING_REVIEW', $submission['status'] ?? null);
        $submissionId = (int) ($submission['id'] ?? 0);
        self::assertGreaterThan(0, $submissionId);

        // ---- credential_reviewer: detail read + request-resubmission ----
        $this->cookieJar = [];
        $reviewerCsrf = $this->loginAs('credential_reviewer');

        $detail = $this->request('GET', sprintf('/api/reviewer/credentials/%d', $submissionId));
        self::assertSame(200, $detail['status']);
        self::assertSame($submissionId, (int) ($this->json($detail['body'])['data']['submission']['id'] ?? 0));

        $decision = $this->request('POST', sprintf('/api/reviewer/credentials/%d/decision', $submissionId), [
            'json' => [
                'action' => 'request_resubmission',
                'comment' => 'Smoke coverage: please resubmit with a clearer scan for lifecycle verification.',
            ],
            'headers' => ['X-CSRF-Token' => $reviewerCsrf],
        ]);
        self::assertSame(200, $decision['status'], 'decision body: ' . $decision['body']);
        self::assertSame(
            'RESUBMISSION_REQUIRED',
            $this->json($decision['body'])['data']['submission']['status'] ?? null,
        );

        // ---- standard_user: resubmit v2 ----
        $this->cookieJar = [];
        $userCsrf = $this->loginAs('standard_user');
        $resubmit = $this->requestMultipart(
            'POST',
            sprintf('/api/practitioner/credentials/%d/resubmit', $submissionId),
            ['label' => 'Smoke Credential (v2) ' . $suffix],
            [
                'file' => [
                    'filename' => 'smoke-credential-v2.pdf',
                    'contentType' => 'application/pdf',
                    'content' => "%PDF-1.4\nResubmission for " . $suffix . "\n%%EOF",
                ],
            ],
            ['X-CSRF-Token' => $userCsrf],
        );
        self::assertSame(200, $resubmit['status'], 'resubmit body: ' . $resubmit['body']);
        self::assertSame(2, (int) ($this->json($resubmit['body'])['data']['submission']['currentVersionNumber'] ?? 0));

        // ---- credential_reviewer: approve v2 ----
        $this->cookieJar = [];
        $reviewerCsrf = $this->loginAs('credential_reviewer');
        $approve = $this->request('POST', sprintf('/api/reviewer/credentials/%d/decision', $submissionId), [
            'json' => ['action' => 'approve', 'comment' => 'Smoke coverage: approved v2.'],
            'headers' => ['X-CSRF-Token' => $reviewerCsrf],
        ]);
        self::assertSame(200, $approve['status']);
        $approved = $this->json($approve['body'])['data']['submission'] ?? [];
        self::assertSame('APPROVED', $approved['status'] ?? null);
        $versionId = (int) ($approved['latestVersion']['id'] ?? 0);
        self::assertGreaterThan(0, $versionId);

        // ---- reviewer can download the approved file (authorized path) ----
        $download = $this->request('GET', sprintf('/api/credentials/versions/%d/download', $versionId));
        self::assertSame(200, $download['status'], 'download body preview: ' . substr($download['body'], 0, 80));
        self::assertStringContainsString('application/pdf', strtolower($download['headers']['content-type'] ?? ''));
        self::assertStringContainsString('attachment;', strtolower($download['headers']['content-disposition'] ?? ''));
    }
}
