<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\CredentialSubmission;
use App\Entity\CredentialSubmissionVersion;
use App\Entity\PractitionerProfile;
use App\Entity\User;
use App\Security\FieldEncryptionService;
use App\Service\LicenseNumberMasker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fills the verification gap for routes that had no dedicated coverage.
 *
 * Each test asserts:
 *  - unauthenticated / unauthorized access is rejected (401/403)
 *  - a role with the correct permission receives a 2xx with a shape contract
 */
final class ApiRouteCoverageTest extends WebTestCase
{
    public function testGetAuthMeReturnsUnauthenticatedWhenNoSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/auth/me');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('UNAUTHENTICATED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
    }

    public function testGetAuthMeReturnsUsernameAndRolesAfterLogin(): void
    {
        $client = static::createClient();
        $this->login($client, 'standard_user');

        $client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();

        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame('standard_user', $payload['data']['username'] ?? null);
        self::assertContains('ROLE_STANDARD_USER', (array) ($payload['data']['roles'] ?? []));
    }

    public function testGetSchedulingConfigurationRequiresSchedulingAdmin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/scheduling/configuration');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'standard_user');
        $client->request('GET', '/api/scheduling/configuration');
        self::assertResponseStatusCodeSame(403);

        $this->logoutIfPossible($client);
        $this->login($client, 'system_admin');
        $client->request('GET', '/api/scheduling/configuration');
        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());
        self::assertArrayHasKey('configuration', $payload['data'] ?? []);
    }

    public function testReleaseHoldEndpointMovesHoldToReleasedState(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/scheduling/holds/1/release');
        self::assertResponseStatusCodeSame(401);

        $slotId = $this->createFutureSlot($client, '+7 days', '+7 days +30 minutes');
        $this->login($client, 'standard_user');
        $csrf = $this->csrf($client);

        $client->request('POST', sprintf('/api/scheduling/slots/%d/hold', $slotId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $holdId = (int) ($this->json($client->getResponse()->getContent())['data']['holdId'] ?? 0);
        self::assertGreaterThan(0, $holdId);

        $client->request('POST', sprintf('/api/scheduling/holds/%d/release', $holdId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('RELEASED', $this->json($client->getResponse()->getContent())['data']['status'] ?? null);

        // re-book should succeed after release because the hold was returned to inventory
        $client->request('POST', sprintf('/api/scheduling/slots/%d/hold', $slotId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    public function testGetSchedulingBookingsMeReturnsCurrentUserBookings(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/scheduling/bookings/me');
        self::assertResponseStatusCodeSame(401);

        $slotId = $this->createFutureSlot($client, '+8 days', '+8 days +30 minutes');
        $this->login($client, 'standard_user');
        $csrf = $this->csrf($client);

        $client->request('POST', sprintf('/api/scheduling/slots/%d/hold', $slotId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        $holdId = (int) ($this->json($client->getResponse()->getContent())['data']['holdId'] ?? 0);

        $client->request('POST', sprintf('/api/scheduling/holds/%d/book', $holdId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $client->request('GET', '/api/scheduling/bookings/me');
        self::assertResponseIsSuccessful();

        $payload = $this->json($client->getResponse()->getContent());
        $bookings = $payload['data']['bookings'] ?? [];
        self::assertIsArray($bookings);
        self::assertNotEmpty($bookings);
        self::assertSame('standard_user', $bookings[0]['bookedByUsername'] ?? null);
    }

    public function testGetPractitionerProfileReturnsNullWhenAbsentAndDataAfterUpsert(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/practitioner/profile');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'standard_user');
        $client->request('GET', '/api/practitioner/profile');
        self::assertResponseIsSuccessful();
        $empty = $this->json($client->getResponse()->getContent());
        self::assertArrayHasKey('profile', $empty['data'] ?? []);

        $csrf = $this->csrf($client);
        $client->request('PUT', '/api/practitioner/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'lawyerFullName' => 'Coverage Lawyer',
            'firmName' => 'Coverage Firm',
            'barJurisdiction' => 'NY',
            'licenseNumber' => 'NY-COV-4477',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/practitioner/profile');
        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame('Coverage Lawyer', $payload['data']['profile']['lawyerFullName'] ?? null);
        self::assertSame('NY', $payload['data']['profile']['barJurisdiction'] ?? null);
    }

    public function testGetPractitionerCredentialsReportsProfileRequiredBeforeUpsert(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/practitioner/credentials');
        self::assertResponseStatusCodeSame(401);

        // Register a brand-new user so the profileRequired=true branch is exercised
        // regardless of whether other tests have already upserted a profile for
        // the shared standard_user fixture.
        $freshUsername = 'creds_cov_' . bin2hex(random_bytes(4));
        $freshPassword = 'CoverageUserPass123!';
        $client->request('POST', '/api/auth/register', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $freshUsername,
            'password' => $freshPassword,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $freshUsername,
            'password' => $freshPassword,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/practitioner/credentials');
        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());
        self::assertTrue((bool) ($payload['data']['profileRequired'] ?? false));
        self::assertSame([], $payload['data']['submissions'] ?? null);
    }

    public function testGetReviewerCredentialDetailRequiresReviewerAndResolvesExistingSubmission(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/reviewer/credentials/1');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'standard_user');
        $client->request('GET', '/api/reviewer/credentials/1');
        self::assertResponseStatusCodeSame(403);

        $submissionId = $this->seedReviewerDetailSubmission($client);

        $this->logoutIfPossible($client);
        $this->login($client, 'credential_reviewer');

        $client->request('GET', sprintf('/api/reviewer/credentials/%d', $submissionId));
        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame($submissionId, (int) ($payload['data']['submission']['id'] ?? 0));
        self::assertArrayHasKey('versions', $payload['data']['submission'] ?? []);

        $client->request('GET', '/api/reviewer/credentials/9999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testGetAnalyticsFeaturesRequiresAnalyticsPermission(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/analytics/features');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'standard_user');
        $client->request('GET', '/api/analytics/features');
        self::assertResponseStatusCodeSame(403);

        $this->logoutIfPossible($client);
        $this->login($client, 'analyst_user');
        $client->request('GET', '/api/analytics/features');
        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());
        self::assertIsArray($payload['data']['features'] ?? null);
    }

    public function testGetAdminAnomaliesRequiresAuditRead(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/admin/governance/anomalies');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'standard_user');
        $client->request('GET', '/api/admin/governance/anomalies');
        self::assertResponseStatusCodeSame(403);

        $this->logoutIfPossible($client);
        $this->login($client, 'system_admin');
        $client->request('GET', '/api/admin/governance/anomalies');
        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame('OPEN', $payload['data']['statusFilter'] ?? null);
        self::assertIsArray($payload['data']['alerts'] ?? null);

        $client->request('GET', '/api/admin/governance/anomalies?status=NOT_A_REAL_STATUS');
        self::assertResponseStatusCodeSame(422);
    }

    public function testGetRollbackCatalogsRequireAdminRollbackPermission(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/admin/governance/rollback/credential-submissions');
        self::assertResponseStatusCodeSame(401);

        $client->request('GET', '/api/admin/governance/rollback/question-entries');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'standard_user');
        $client->request('GET', '/api/admin/governance/rollback/credential-submissions');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/admin/governance/rollback/question-entries');
        self::assertResponseStatusCodeSame(403);

        $this->logoutIfPossible($client);
        $this->login($client, 'system_admin');

        $client->request('GET', '/api/admin/governance/rollback/credential-submissions');
        self::assertResponseIsSuccessful();
        $credentials = $this->json($client->getResponse()->getContent());
        self::assertIsArray($credentials['data']['submissions'] ?? null);

        $client->request('GET', '/api/admin/governance/rollback/question-entries');
        self::assertResponseIsSuccessful();
        $questions = $this->json($client->getResponse()->getContent());
        self::assertIsArray($questions['data']['entries'] ?? null);
    }

    public function testGetAdminHumanVerificationStatusRequiresSystemAdminRole(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/admin/integrations/human-verification');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, 'standard_user');
        $client->request('GET', '/api/admin/integrations/human-verification');
        self::assertResponseStatusCodeSame(403);

        $this->logoutIfPossible($client);
        $this->login($client, 'system_admin');
        $client->request('GET', '/api/admin/integrations/human-verification');
        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame('DISABLED', $payload['data']['status'] ?? null);
        self::assertFalse((bool) ($payload['data']['networkDependencyRequired'] ?? true));
    }

    private function seedReviewerDetailSubmission(KernelBrowser $client): int
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $encryption = $client->getContainer()->get(FieldEncryptionService::class);
        self::assertInstanceOf(FieldEncryptionService::class, $encryption);
        $masker = $client->getContainer()->get(LicenseNumberMasker::class);
        self::assertInstanceOf(LicenseNumberMasker::class, $masker);

        $username = 'coverage_' . bin2hex(random_bytes(4));
        $user = new User($username);
        $user->setRoles(['ROLE_STANDARD_USER']);
        $user->setPasswordHash('seed_hash');
        $entityManager->persist($user);
        $entityManager->flush();

        $license = 'CA-COV-' . substr($username, -4);
        $profile = new PractitionerProfile(
            $user,
            'Coverage Reviewer Target',
            'Coverage Firm',
            'CA',
            $encryption->encrypt($license),
            $masker->mask($license),
        );
        $entityManager->persist($profile);
        $entityManager->flush();

        $submission = new CredentialSubmission($profile, 'Coverage Reviewer Submission');
        $submission->markPendingReview(1);
        $version = new CredentialSubmissionVersion(
            $submission,
            1,
            '/tmp/coverage.pdf',
            'coverage.pdf',
            'application/pdf',
            1024,
            $username,
        );
        $entityManager->persist($submission);
        $entityManager->persist($version);
        $entityManager->flush();

        return (int) $submission->getId();
    }

    private function createFutureSlot(KernelBrowser $client, string $start, string $end): int
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(4));
        $slot = new \App\Entity\AppointmentSlot(
            new \DateTimeImmutable($start, new \DateTimeZone('UTC')),
            new \DateTimeImmutable($end, new \DateTimeZone('UTC')),
            1,
            'Coverage Practitioner ' . $suffix,
            'Coverage Room ' . $suffix,
            'system_admin',
        );
        $entityManager->persist($slot);
        $entityManager->flush();

        return (int) $slot->getId();
    }

    private function login(KernelBrowser $client, string $username): void
    {
        $devPassword = getenv('DEV_BOOTSTRAP_PASSWORD');
        self::assertIsString($devPassword);
        self::assertNotSame('', $devPassword);

        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $username,
            'password' => $devPassword,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
    }

    private function csrf(KernelBrowser $client): string
    {
        $client->request('GET', '/api/auth/csrf-token');
        self::assertResponseIsSuccessful();

        return (string) ($this->json($client->getResponse()->getContent())['data']['csrfToken'] ?? '');
    }

    private function logoutIfPossible(KernelBrowser $client): void
    {
        $csrf = $this->csrf($client);
        $client->request('POST', '/api/auth/logout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function json(string|false $content): array
    {
        return is_string($content) ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
