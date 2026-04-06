<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGovernanceControllerTest extends WebTestCase
{
    public function testAuditAndSensitiveLogAccessIsSystemAdminOnly(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/admin/governance/audit-logs');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'standard_user', $this->devPassword());
        $client->request('GET', '/api/admin/governance/audit-logs');
        self::assertResponseStatusCodeSame(403);

        $standardCsrf = $this->csrf($client);
        $client->request('POST', '/api/admin/governance/sensitive/practitioner-profiles/1/license', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $standardCsrf,
        ], content: json_encode([
            'reason' => 'Unauthorized sensitive-read attempt as standard user.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        $client->request('PUT', '/api/practitioner/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $standardCsrf,
        ], content: json_encode([
            'lawyerFullName' => 'Sensitive Access Candidate',
            'firmName' => 'Firm Sensitive Access',
            'barJurisdiction' => 'CA',
            'licenseNumber' => 'CA-778811',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $profileId = (int) ($this->json($client->getResponse()->getContent())['data']['profile']['id'] ?? 0);
        self::assertGreaterThan(0, $profileId);

        $this->logout($client, $standardCsrf);
        $this->login($client, 'system_admin', $this->devPassword());
        $adminCsrf = $this->csrf($client);

        $client->request('GET', '/api/admin/governance/audit-logs');
        self::assertResponseIsSuccessful();
        $auditPayload = $this->json($client->getResponse()->getContent());
        self::assertTrue((bool) ($auditPayload['data']['immutable'] ?? false));
        self::assertIsArray($auditPayload['data']['logs'] ?? null);
        self::assertSame(7, (int) ($auditPayload['data']['retentionPolicy']['minimumRetentionYears'] ?? 0));
        self::assertNotSame('', (string) ($auditPayload['data']['retentionPolicy']['purgeEligibleBeforeUtc'] ?? ''));

        $client->request('POST', sprintf('/api/admin/governance/sensitive/practitioner-profiles/%d/license', $profileId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $adminCsrf,
        ], content: json_encode([
            'reason' => 'Operational audit review for sensitive-access pipeline verification.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('CA-778811', $this->json($client->getResponse()->getContent())['data']['licenseNumber'] ?? null);

        $client->request('GET', '/api/admin/governance/sensitive-access-logs');
        self::assertResponseIsSuccessful();
        $sensitivePayload = $this->json($client->getResponse()->getContent());
        self::assertNotEmpty($sensitivePayload['data']['logs'] ?? []);
        self::assertSame('license_number', $sensitivePayload['data']['logs'][0]['fieldName'] ?? null);
        self::assertSame(7, (int) ($sensitivePayload['data']['retentionPolicy']['minimumRetentionYears'] ?? 0));
    }

    public function testAnomalyRefreshDetectsRejectedCredentialSpikeAndSupportsAcknowledgement(): void
    {
        $client = static::createClient();
        $this->login($client, 'system_admin', $this->devPassword());
        $csrf = $this->csrf($client);

        $firmName = 'Anomaly Firm '.$this->uniqueSuffix();
        $this->seedRejectedSpike($client, $firmName, 6);

        $client->request('POST', '/api/admin/governance/anomalies/refresh', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $refreshPayload = $this->json($client->getResponse()->getContent());
        $alerts = $refreshPayload['data']['alerts'] ?? [];
        self::assertIsArray($alerts);

        $matchingAlert = null;
        foreach ($alerts as $alert) {
            if (($alert['alertType'] ?? null) === 'CREDENTIAL_REJECTION_SPIKE' && ($alert['payload']['firmName'] ?? null) === $firmName) {
                $matchingAlert = $alert;
                break;
            }
        }

        self::assertIsArray($matchingAlert);
        self::assertGreaterThan(5, (int) ($matchingAlert['payload']['rejectedCount'] ?? 0));

        $alertId = (int) ($matchingAlert['id'] ?? 0);
        self::assertGreaterThan(0, $alertId);

        $client->request('POST', sprintf('/api/admin/governance/anomalies/%d/acknowledge', $alertId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'note' => 'Investigating rejection spike with compliance operations lead.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $ackPayload = $this->json($client->getResponse()->getContent());
        self::assertSame('ACKNOWLEDGED', $ackPayload['data']['alert']['status'] ?? null);
    }

    public function testCredentialRollbackRequiresStepUpAndJustificationAndCreatesNewVersion(): void
    {
        $client = static::createClient();
        $this->login($client, 'system_admin', $this->devPassword());
        $csrf = $this->csrf($client);

        $seed = $this->seedRollbackCredentialSubmission($client, 'Rollback Firm '.$this->uniqueSuffix());
        $submissionId = $seed['submissionId'];

        $client->request('POST', '/api/admin/governance/rollback/credentials', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'submissionId' => $submissionId,
            'targetVersionNumber' => 1,
            'stepUpPassword' => 'wrong-password',
            'justificationNote' => 'Need to restore previously approved regulatory submission evidence.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('VALIDATION_ERROR', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);

        $client->request('POST', '/api/admin/governance/rollback/credentials', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'submissionId' => $submissionId,
            'targetVersionNumber' => 1,
            'stepUpPassword' => $this->devPassword(),
            'justificationNote' => 'Restoring previously verified file after incorrect reviewer overwrite.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame(1, (int) ($payload['data']['rolledBackFromVersion'] ?? 0));
        self::assertSame(3, (int) ($payload['data']['newVersionNumber'] ?? 0));
        self::assertSame(3, (int) ($payload['data']['submission']['currentVersionNumber'] ?? 0));
    }

    public function testQuestionRollbackAndAdminPasswordResetWorkflow(): void
    {
        $client = static::createClient();
        $this->login($client, 'content_admin', $this->devPassword());
        $contentCsrf = $this->csrf($client);

        $client->request('POST', '/api/question-bank/questions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $contentCsrf,
        ], content: json_encode([
            'title' => 'Rollback Question Base',
            'plainTextContent' => 'Base question text for rollback version test.',
            'richTextContent' => '<p>Base question text for rollback version test.</p>',
            'difficulty' => 2,
            'tags' => ['rollback', 'base'],
            'formulas' => [],
            'embeddedAssetIds' => [],
            'changeNote' => 'Base version',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $entryId = (int) ($this->json($client->getResponse()->getContent())['data']['entry']['id'] ?? 0);
        self::assertGreaterThan(0, $entryId);

        $client->request('PUT', sprintf('/api/question-bank/questions/%d', $entryId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $contentCsrf,
        ], content: json_encode([
            'title' => 'Rollback Question Updated',
            'plainTextContent' => 'Updated question content',
            'richTextContent' => '<p>Updated question content</p>',
            'difficulty' => 3,
            'tags' => ['rollback', 'updated'],
            'formulas' => [],
            'embeddedAssetIds' => [],
            'changeNote' => 'Updated version',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->logout($client, $contentCsrf);

        $this->login($client, 'system_admin', $this->devPassword());
        $adminCsrf = $this->csrf($client);

        $client->request('POST', '/api/admin/governance/rollback/questions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $adminCsrf,
        ], content: json_encode([
            'entryId' => $entryId,
            'targetVersionNumber' => 1,
            'stepUpPassword' => $this->devPassword(),
            'justificationNote' => 'Restore prior approved wording after change-control issue.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $rollbackPayload = $this->json($client->getResponse()->getContent());
        self::assertSame(3, (int) ($rollbackPayload['data']['newVersionNumber'] ?? 0));
        self::assertSame(3, (int) ($rollbackPayload['data']['entry']['currentVersionNumber'] ?? 0));

        $resetUsername = 'reset_user_'.bin2hex(random_bytes(4));
        $oldPassword = 'InitialPassword123!';
        $newPassword = 'AdminResetPassword123!';

        $this->logout($client, $adminCsrf);
        $client->request('POST', '/api/auth/register', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $resetUsername,
            'password' => $oldPassword,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->login($client, $resetUsername, $oldPassword);
        $userCsrf = $this->csrf($client);
        $client->request('POST', '/api/admin/governance/users/password-reset', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $userCsrf,
        ], content: json_encode([
            'targetUsername' => 'standard_user',
            'newPassword' => 'AnotherPassword123!',
            'stepUpPassword' => $oldPassword,
            'justificationNote' => 'Unauthorized reset attempt.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        $this->logout($client, $userCsrf);
        $this->login($client, 'system_admin', $this->devPassword());
        $adminCsrf = $this->csrf($client);
        $client->request('POST', '/api/admin/governance/users/password-reset', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $adminCsrf,
        ], content: json_encode([
            'targetUsername' => $resetUsername,
            'newPassword' => $newPassword,
            'stepUpPassword' => $this->devPassword(),
            'justificationNote' => 'User requested admin-only reset after token device loss.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->logout($client, $adminCsrf);
        $this->login($client, $resetUsername, $newPassword);
        self::assertResponseIsSuccessful();
    }

    private function seedRejectedSpike($client, string $firmName, int $rejectedCount): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $username = 'anomaly_'.bin2hex(random_bytes(4));
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $connection->executeStatement(
            'INSERT INTO users (username, roles, password_hash, failed_attempt_count, locked_until, created_at_utc, updated_at_utc) VALUES (?, ?, ?, 0, NULL, ?, ?)',
            [$username, json_encode(['ROLE_STANDARD_USER'], JSON_THROW_ON_ERROR), 'seed_hash', $now, $now],
        );
        $userId = (int) $connection->lastInsertId();

        $connection->executeStatement(
            'INSERT INTO practitioner_profiles (user_id, lawyer_full_name, firm_name, bar_jurisdiction, license_key_id, license_nonce, license_ciphertext, license_auth_tag, license_number_masked, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, 'Anomaly Lawyer', $firmName, 'CA', 'dev-key', base64_encode('nonce'), base64_encode('cipher'), base64_encode('tag'), '••••1234', $now, $now],
        );
        $profileId = (int) $connection->lastInsertId();

        for ($i = 1; $i <= $rejectedCount; ++$i) {
            $connection->executeStatement(
                'INSERT INTO credential_submissions (practitioner_profile_id, label, status, current_version_number, created_at_utc, updated_at_utc) VALUES (?, ?, ?, 1, ?, ?)',
                [$profileId, sprintf('Rejected %d', $i), 'REJECTED', $now, $now],
            );
            $submissionId = (int) $connection->lastInsertId();

            $connection->executeStatement(
                'INSERT INTO credential_submission_versions (submission_id, version_number, storage_path, original_filename, mime_type, size_bytes, review_status, review_comment, reviewed_by_username, reviewed_at_utc, uploaded_by_username, uploaded_at_utc) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$submissionId, '/tmp/fake.pdf', 'fake.pdf', 'application/pdf', 1024, 'REJECTED', 'Automated seed rejection', 'credential_reviewer', $now, $username, $now],
            );
        }
    }

    /** @return array{submissionId: int} */
    private function seedRollbackCredentialSubmission($client, string $firmName): array
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $username = 'rollback_'.bin2hex(random_bytes(4));
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $earlier = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d H:i:s');

        $connection->executeStatement(
            'INSERT INTO users (username, roles, password_hash, failed_attempt_count, locked_until, created_at_utc, updated_at_utc) VALUES (?, ?, ?, 0, NULL, ?, ?)',
            [$username, json_encode(['ROLE_STANDARD_USER'], JSON_THROW_ON_ERROR), 'seed_hash', $now, $now],
        );
        $userId = (int) $connection->lastInsertId();

        $connection->executeStatement(
            'INSERT INTO practitioner_profiles (user_id, lawyer_full_name, firm_name, bar_jurisdiction, license_key_id, license_nonce, license_ciphertext, license_auth_tag, license_number_masked, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, 'Rollback Lawyer', $firmName, 'TX', 'dev-key', base64_encode('nonce'), base64_encode('cipher'), base64_encode('tag'), '••••7788', $now, $now],
        );
        $profileId = (int) $connection->lastInsertId();

        $connection->executeStatement(
            'INSERT INTO credential_submissions (practitioner_profile_id, label, status, current_version_number, created_at_utc, updated_at_utc) VALUES (?, ?, ?, 2, ?, ?)',
            [$profileId, 'Rollback Submission', 'APPROVED', $now, $now],
        );
        $submissionId = (int) $connection->lastInsertId();

        $connection->executeStatement(
            'INSERT INTO credential_submission_versions (submission_id, version_number, storage_path, original_filename, mime_type, size_bytes, review_status, review_comment, reviewed_by_username, reviewed_at_utc, uploaded_by_username, uploaded_at_utc) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$submissionId, '/tmp/v1.pdf', 'v1.pdf', 'application/pdf', 1000, 'APPROVED', 'Approved version one', 'credential_reviewer', $earlier, $username, $earlier],
        );
        $connection->executeStatement(
            'INSERT INTO credential_submission_versions (submission_id, version_number, storage_path, original_filename, mime_type, size_bytes, review_status, review_comment, reviewed_by_username, reviewed_at_utc, uploaded_by_username, uploaded_at_utc) VALUES (?, 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$submissionId, '/tmp/v2.pdf', 'v2.pdf', 'application/pdf', 1200, 'APPROVED', 'Approved version two', 'credential_reviewer', $now, $username, $now],
        );

        return ['submissionId' => $submissionId];
    }

    private function login($client, string $username, string $password): void
    {
        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $username,
            'password' => $password,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
    }

    private function logout($client, string $csrf): void
    {
        $client->request('POST', '/api/auth/logout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    private function csrf($client): string
    {
        $client->request('GET', '/api/auth/csrf-token');
        self::assertResponseIsSuccessful();

        return (string) ($this->json($client->getResponse()->getContent())['data']['csrfToken'] ?? '');
    }

    private function devPassword(): string
    {
        $password = getenv('DEV_BOOTSTRAP_PASSWORD');
        self::assertIsString($password);
        self::assertNotSame('', $password);

        return $password;
    }

    private function uniqueSuffix(): string
    {
        return bin2hex(random_bytes(4));
    }

    /** @return array<string, mixed> */
    private function json(string|false $content): array
    {
        return is_string($content) ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
