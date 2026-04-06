<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Repository\PractitionerProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PractitionerCredentialWorkflowTest extends WebTestCase
{
    public function testStandardUserCanMaintainProfileAndUploadCredential(): void
    {
        $client = static::createClient();
        $this->login($client, 'standard_user', $this->devPassword());
        $csrf = $this->csrf($client);

        $licenseNumber = 'BAR-447122';

        $client->request('PUT', '/api/practitioner/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'lawyerFullName' => 'Ariya Chen',
            'firmName' => 'Northbridge Legal Partners',
            'barJurisdiction' => 'CA',
            'licenseNumber' => $licenseNumber,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $profileResponse = $this->json($client->getResponse()->getContent());
        self::assertArrayHasKey('licenseNumberMasked', $profileResponse['data']['profile'] ?? []);
        self::assertNotSame($licenseNumber, $profileResponse['data']['profile']['licenseNumberMasked'] ?? null);

        $profileRepository = static::getContainer()->get(PractitionerProfileRepository::class);
        $userRepository = static::getContainer()->get(UserRepository::class);
        $standardUser = $userRepository->findOneByUsername('standard_user');
        self::assertNotNull($standardUser);

        $profile = $profileRepository->findOneBy(['user' => $standardUser]);
        self::assertNotNull($profile);
        self::assertNotSame($licenseNumber, $profile?->encryptedLicensePayload()['ciphertext'] ?? null);

        $upload = $this->makeUpload('%PDF-1.4 credential payload', 'bar-license.pdf', 'application/pdf');
        $client->request('POST', '/api/practitioner/credentials', [
            'label' => 'State Bar Admission Certificate',
        ], [
            'file' => $upload,
        ], [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);

        self::assertResponseStatusCodeSame(201);
        $submissionPayload = $this->json($client->getResponse()->getContent());
        self::assertSame('PENDING_REVIEW', $submissionPayload['data']['submission']['status'] ?? null);
        self::assertSame(1, $submissionPayload['data']['submission']['currentVersionNumber'] ?? null);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $auditCount = (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE action_type = :action AND actor_username = :actor',
            ['action' => 'credential.uploaded', 'actor' => 'standard_user'],
        );
        self::assertGreaterThan(0, $auditCount);
    }

    public function testReviewerDecisionFlowEnforcesCommentsAndObjectAuthorization(): void
    {
        $client = static::createClient();
        $this->login($client, 'standard_user', $this->devPassword());
        $csrf = $this->csrf($client);

        $client->request('PUT', '/api/practitioner/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'lawyerFullName' => 'Ariya Chen',
            'firmName' => 'Northbridge Legal Partners',
            'barJurisdiction' => 'CA',
            'licenseNumber' => 'BAR-991188',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $uploadV1 = $this->makeUpload('%PDF-1.4 first submission', 'credential-v1.pdf', 'application/pdf');
        $client->request('POST', '/api/practitioner/credentials', [
            'label' => 'Identity and Bar Credential',
        ], [
            'file' => $uploadV1,
        ], [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseStatusCodeSame(201);

        $submissionId = (int) ($this->json($client->getResponse()->getContent())['data']['submission']['id'] ?? 0);
        self::assertGreaterThan(0, $submissionId);

        $intruderName = 'intruder_'.bin2hex(random_bytes(4));
        $intruderPassword = 'IntruderPassword123!';
        $client->request('POST', '/api/auth/register', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $intruderName,
            'password' => $intruderPassword,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/auth/logout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->login($client, $intruderName, $intruderPassword);
        $intruderCsrf = $this->csrf($client);
        $intruderUpload = $this->makeUpload('%PDF-1.4 intruder attempt', 'intruder.pdf', 'application/pdf');
        $client->request('POST', sprintf('/api/practitioner/credentials/%d/resubmit', $submissionId), [], [
            'file' => $intruderUpload,
        ], [
            'HTTP_X_CSRF_TOKEN' => $intruderCsrf,
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/api/auth/logout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $intruderCsrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->login($client, 'credential_reviewer', $this->devPassword());
        $reviewerCsrf = $this->csrf($client);

        $client->request('POST', sprintf('/api/reviewer/credentials/%d/decision', $submissionId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $reviewerCsrf,
        ], content: json_encode([
            'action' => 'reject',
            'comment' => '',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $client->request('POST', sprintf('/api/reviewer/credentials/%d/decision', $submissionId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $reviewerCsrf,
        ], content: json_encode([
            'action' => 'request_resubmission',
            'comment' => 'Document edge is truncated. Please provide a clearer scan.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame(
            'RESUBMISSION_REQUIRED',
            $this->json($client->getResponse()->getContent())['data']['submission']['status'] ?? null,
        );

        $client->request('POST', '/api/auth/logout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $reviewerCsrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->login($client, 'standard_user', $this->devPassword());
        $standardCsrf = $this->csrf($client);
        $uploadV2 = $this->makeUpload('%PDF-1.4 resubmission', 'credential-v2.pdf', 'application/pdf');
        $client->request('POST', sprintf('/api/practitioner/credentials/%d/resubmit', $submissionId), [
            'label' => 'Identity and Bar Credential (rescanned)',
        ], [
            'file' => $uploadV2,
        ], [
            'HTTP_X_CSRF_TOKEN' => $standardCsrf,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json($client->getResponse()->getContent())['data']['submission']['currentVersionNumber'] ?? null);

        $client->request('POST', '/api/auth/logout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $standardCsrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->login($client, 'credential_reviewer', $this->devPassword());
        $reviewerCsrf = $this->csrf($client);
        $client->request('POST', sprintf('/api/reviewer/credentials/%d/decision', $submissionId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $reviewerCsrf,
        ], content: json_encode([
            'action' => 'approve',
            'comment' => 'Verification complete.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', $this->json($client->getResponse()->getContent())['data']['submission']['status'] ?? null);

        $queuePayload = $this->queue($client, 'APPROVED');
        $found = false;
        foreach (($queuePayload['data']['queue'] ?? []) as $item) {
            if (($item['id'] ?? null) === $submissionId) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $decisionAuditCount = (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_logs WHERE action_type = :action',
            ['action' => 'credential.review_decision'],
        );
        self::assertGreaterThan(0, $decisionAuditCount);
    }

    public function testSystemAdminCanPerformCredentialOversightReviewAndDownload(): void
    {
        $client = static::createClient();
        $this->login($client, 'standard_user', $this->devPassword());
        $csrf = $this->csrf($client);

        $client->request('PUT', '/api/practitioner/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'lawyerFullName' => 'Admin Oversight User',
            'firmName' => 'Oversight Legal',
            'barJurisdiction' => 'NY',
            'licenseNumber' => 'NY-771100',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $upload = $this->makeUpload('%PDF-1.4 admin oversight flow', 'oversight.pdf', 'application/pdf');
        $client->request('POST', '/api/practitioner/credentials', [
            'label' => 'Oversight Scenario Credential',
        ], [
            'file' => $upload,
        ], [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseStatusCodeSame(201);

        $submissionId = (int) ($this->json($client->getResponse()->getContent())['data']['submission']['id'] ?? 0);
        self::assertGreaterThan(0, $submissionId);

        $client->request('POST', '/api/auth/logout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->login($client, 'system_admin', $this->devPassword());
        $adminCsrf = $this->csrf($client);

        $queue = $this->queue($client, 'PENDING_REVIEW');
        $matched = null;
        foreach (($queue['data']['queue'] ?? []) as $entry) {
            if (($entry['id'] ?? null) === $submissionId) {
                $matched = $entry;
                break;
            }
        }
        self::assertIsArray($matched);

        $client->request('POST', sprintf('/api/reviewer/credentials/%d/decision', $submissionId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $adminCsrf,
        ], content: json_encode([
            'action' => 'approve',
            'comment' => 'Administrative oversight approval.',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $decisionPayload = $this->json($client->getResponse()->getContent());
        self::assertSame('APPROVED', $decisionPayload['data']['submission']['status'] ?? null);

        $versionId = (int) ($decisionPayload['data']['submission']['latestVersion']['id'] ?? 0);
        self::assertGreaterThan(0, $versionId);

        $client->request('GET', sprintf('/api/credentials/versions/%d/download', $versionId));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'attachment;',
            (string) $client->getResponse()->headers->get('content-disposition'),
        );
    }

    private function login(KernelBrowser $client, string $username, string $password): void
    {
        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $username,
            'password' => $password,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
    }

    private function csrf(KernelBrowser $client): string
    {
        $client->request('GET', '/api/auth/csrf-token');
        self::assertResponseIsSuccessful();

        return (string) ($this->json($client->getResponse()->getContent())['data']['csrfToken'] ?? '');
    }

    /** @return array<string, mixed> */
    private function queue(KernelBrowser $client, string $status): array
    {
        $client->request('GET', '/api/reviewer/credentials/queue?status='.rawurlencode($status));
        self::assertResponseIsSuccessful();

        return $this->json($client->getResponse()->getContent());
    }

    private function makeUpload(string $content, string $filename, string $mimeType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cred_');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, $mimeType, test: true);
    }

    private function devPassword(): string
    {
        $password = getenv('DEV_BOOTSTRAP_PASSWORD');
        self::assertIsString($password);
        self::assertNotSame('', $password);

        return $password;
    }

    /** @return array<string, mixed> */
    private function json(string|false $content): array
    {
        return is_string($content) ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
