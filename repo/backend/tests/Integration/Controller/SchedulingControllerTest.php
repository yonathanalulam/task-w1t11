<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AppointmentSlot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SchedulingControllerTest extends WebTestCase
{
    public function testListSlotsRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/scheduling/slots');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('UNAUTHENTICATED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
    }

    public function testAdminCanConfigureAndGenerateSlots(): void
    {
        $client = static::createClient();
        $this->login($client, 'system_admin');
        $csrfToken = $this->fetchCsrfToken($client);
        $suffix = $this->uniqueSuffix();

        $client->request('PUT', '/api/scheduling/configuration', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode([
            'practitionerName' => sprintf('Ariya Chen %s', $suffix),
            'locationName' => sprintf('HQ-%s', $suffix),
            'slotDurationMinutes' => 30,
            'slotCapacity' => 1,
            'weeklyAvailability' => [
                ['weekday' => 1, 'startTime' => '09:00', 'endTime' => '11:00'],
                ['weekday' => 2, 'startTime' => '09:00', 'endTime' => '11:00'],
            ],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/scheduling/slots/generate', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['daysAhead' => 14], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = $this->json($client->getResponse()->getContent());
        self::assertGreaterThan(0, (int) ($payload['data']['createdCount'] ?? 0));
    }

    public function testHoldAndBookFlowProvidesConflictFeedback(): void
    {
        $client = static::createClient();
        $suffix = $this->uniqueSuffix();
        $slotId = $this->createFutureSlot($client, '+3 days', '+3 days +30 minutes', sprintf('HQ-%s', $suffix), sprintf('Ariya Chen %s', $suffix));
        self::assertGreaterThan(0, $slotId);
        $this->login($client, 'standard_user');
        $csrfToken = $this->fetchCsrfToken($client);

        $client->request('POST', sprintf('/api/scheduling/slots/%d/hold', $slotId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $holdId = (int) ($this->json($client->getResponse()->getContent())['data']['holdId'] ?? 0);
        self::assertGreaterThan(0, $holdId);

        $client->request('POST', sprintf('/api/scheduling/holds/%d/book', $holdId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', sprintf('/api/scheduling/holds/%d/book', $holdId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode([], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('HOLD_NOT_ACTIVE', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
    }

    public function testBookingHorizonOverNinetyDaysIsRejected(): void
    {
        $client = static::createClient();
        $suffix = $this->uniqueSuffix();
        $slotId = $this->createFutureSlot($client, '+91 days', '+91 days +30 minutes', sprintf('HQ-%s', $suffix), sprintf('Ariya Chen %s', $suffix));
        $this->login($client, 'standard_user');
        $csrfToken = $this->fetchCsrfToken($client);

        $client->request('POST', sprintf('/api/scheduling/slots/%d/hold', $slotId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode([], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('BOOKING_HORIZON_EXCEEDED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
    }

    public function testRescheduleLimitAndCancelWindowRules(): void
    {
        $client = static::createClient();
        $suffix = $this->uniqueSuffix();
        $practitioner = sprintf('Ariya Chen %s', $suffix);
        $sourceSlotId = $this->createFutureSlot($client, '+3 days', '+3 days +30 minutes', sprintf('Room A-%s', $suffix), $practitioner);
        $targetOneId = $this->createFutureSlot($client, '+4 days', '+4 days +30 minutes', sprintf('Room A-%s', $suffix), $practitioner);
        $targetTwoId = $this->createFutureSlot($client, '+5 days', '+5 days +30 minutes', sprintf('Room A-%s', $suffix), $practitioner);
        $targetThreeId = $this->createFutureSlot($client, '+6 days', '+6 days +30 minutes', sprintf('Room A-%s', $suffix), $practitioner);
        $nearTermSlotId = $this->createFutureSlot($client, '+20 hours', '+20 hours +30 minutes', sprintf('Room B-%s', $suffix), $practitioner);
        $this->login($client, 'standard_user');
        $csrfToken = $this->fetchCsrfToken($client);

        $bookingId = $this->holdAndBook($client, $csrfToken, $sourceSlotId);

        $this->reschedule($client, $csrfToken, $bookingId, $targetOneId, 200);
        $this->reschedule($client, $csrfToken, $bookingId, $targetTwoId, 200);
        $this->reschedule($client, $csrfToken, $bookingId, $targetThreeId, 409, 'RESCHEDULE_LIMIT_REACHED');

        $nearBookingId = $this->holdAndBook($client, $csrfToken, $nearTermSlotId);
        $client->request('POST', sprintf('/api/scheduling/bookings/%d/cancel', $nearBookingId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['reason' => 'Personal conflict'], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('CANCEL_WINDOW_RESTRICTED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);

        $this->logout($client, $csrfToken);
        $this->login($client, 'system_admin');
        $adminCsrf = $this->fetchCsrfToken($client);

        $client->request('POST', sprintf('/api/scheduling/bookings/%d/cancel', $nearBookingId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $adminCsrf,
        ], content: json_encode(['reason' => 'Operational override'], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertSame('CANCELLED', $this->json($client->getResponse()->getContent())['data']['booking']['status'] ?? null);
    }

    public function testExpiredHoldCannotBeBookedAndCapacityIsReturnedToInventory(): void
    {
        $client = static::createClient();
        $suffix = $this->uniqueSuffix();
        $slotId = $this->createFutureSlot($client, '+4 days', '+4 days +30 minutes', sprintf('Expiry-%s', $suffix), sprintf('Expiry Practitioner %s', $suffix));

        $this->login($client, 'standard_user');
        $userCsrf = $this->fetchCsrfToken($client);

        $client->request('POST', sprintf('/api/scheduling/slots/%d/hold', $slotId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $userCsrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $holdId = (int) ($this->json($client->getResponse()->getContent())['data']['holdId'] ?? 0);
        self::assertGreaterThan(0, $holdId);

        $this->forceHoldExpiry($client, $holdId);

        $client->request('POST', sprintf('/api/scheduling/holds/%d/book', $holdId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $userCsrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('HOLD_NOT_ACTIVE', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);

        $this->logout($client, $userCsrf);
        $this->login($client, 'system_admin');
        $adminCsrf = $this->fetchCsrfToken($client);

        $client->request('POST', sprintf('/api/scheduling/slots/%d/hold', $slotId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $adminCsrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        self::assertGreaterThan(0, (int) ($this->json($client->getResponse()->getContent())['data']['holdId'] ?? 0));
    }

    public function testOverlappingBookingForSamePractitionerAndLocationIsRejected(): void
    {
        $client = static::createClient();
        $suffix = $this->uniqueSuffix();
        $practitioner = sprintf('Overlap Practitioner %s', $suffix);
        $location = sprintf('Overlap-Loc-%s', $suffix);

        $slotOneId = $this->createFutureSlot($client, '+5 days', '+5 days +45 minutes', $location, $practitioner);
        $slotTwoId = $this->createFutureSlot($client, '+5 days +15 minutes', '+5 days +60 minutes', $location, $practitioner);

        $this->login($client, 'standard_user');
        $userCsrf = $this->fetchCsrfToken($client);
        $this->holdAndBook($client, $userCsrf, $slotOneId);

        $this->logout($client, $userCsrf);
        $this->login($client, 'system_admin');
        $adminCsrf = $this->fetchCsrfToken($client);

        $client->request('POST', sprintf('/api/scheduling/slots/%d/hold', $slotTwoId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $adminCsrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $holdId = (int) ($this->json($client->getResponse()->getContent())['data']['holdId'] ?? 0);
        self::assertGreaterThan(0, $holdId);

        $client->request('POST', sprintf('/api/scheduling/holds/%d/book', $holdId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $adminCsrf,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('PRACTITIONER_LOCATION_CONFLICT', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);

        self::assertSame(0, $this->slotBookedCount($client, $slotTwoId));
    }

    public function testCompetingContentionCannotOverbookSingleCapacitySlot(): void
    {
        $client = static::createClient();

        $suffix = $this->uniqueSuffix();
        $slotId = $this->createFutureSlot(
            $client,
            '+6 days',
            '+6 days +30 minutes',
            sprintf('Contention-Loc-%s', $suffix),
            sprintf('Contention Practitioner %s', $suffix),
        );

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $connection->fetchAssociative('SELECT id FROM appointment_slots WHERE id = ? FOR UPDATE', [$slotId]);

            $workerScript = realpath(__DIR__ . '/../Fixtures/scheduling_contention_worker.php');
            self::assertIsString($workerScript);
            self::assertNotSame('', $workerScript);

            $workerA = $this->startContentionWorker($workerScript, $slotId, 'standard_user');
            $workerB = $this->startContentionWorker($workerScript, $slotId, 'system_admin');

            usleep(300000);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->commit();
            }
        }

        $resultA = $this->awaitContentionWorker($workerA);
        $resultB = $this->awaitContentionWorker($workerB);

        $outcomes = [$resultA['status'] ?? null, $resultB['status'] ?? null];
        $bookedCount = count(array_filter($outcomes, static fn ($status): bool => $status === 'BOOKED'));
        self::assertSame(1, $bookedCount, sprintf('Expected exactly one BOOKED outcome. Worker A: %s Worker B: %s', json_encode($resultA), json_encode($resultB)));

        $errorCodes = array_values(array_filter([
            $resultA['code'] ?? null,
            $resultB['code'] ?? null,
        ], static fn ($value): bool => is_string($value) && $value !== ''));
        self::assertContains('SLOT_UNAVAILABLE', $errorCodes, sprintf('Expected SLOT_UNAVAILABLE on losing worker. Worker A: %s Worker B: %s', json_encode($resultA), json_encode($resultB)));

        self::assertSame(1, $this->slotBookedCount($client, $slotId));
        self::assertSame(1, $this->activeBookingCountForSlot($client, $slotId));
    }

    private function holdAndBook($client, string $csrfToken, int $slotId): int
    {
        $client->request('POST', sprintf('/api/scheduling/slots/%d/hold', $slotId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $holdId = (int) ($this->json($client->getResponse()->getContent())['data']['holdId'] ?? 0);
        self::assertGreaterThan(0, $holdId);

        $client->request('POST', sprintf('/api/scheduling/holds/%d/book', $holdId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return (int) ($this->json($client->getResponse()->getContent())['data']['booking']['id'] ?? 0);
    }

    private function reschedule($client, string $csrfToken, int $bookingId, int $targetSlotId, int $expectedStatus, ?string $expectedCode = null): void
    {
        $client->request('POST', sprintf('/api/scheduling/bookings/%d/reschedule', $bookingId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['targetSlotId' => $targetSlotId], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame($expectedStatus);
        if ($expectedCode !== null) {
            self::assertSame($expectedCode, $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
        }
    }

    private function createFutureSlot($client, string $start, string $end, string $locationName = 'HQ', string $practitionerName = 'Ariya Chen'): int
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $slot = new AppointmentSlot(
            new \DateTimeImmutable($start, new \DateTimeZone('UTC')),
            new \DateTimeImmutable($end, new \DateTimeZone('UTC')),
            1,
            $practitionerName,
            $locationName,
            'system_admin',
        );

        $entityManager->persist($slot);
        $entityManager->flush();

        return (int) $slot->getId();
    }

    private function forceHoldExpiry($client, int $holdId): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->getConnection()->executeStatement(
            'UPDATE appointment_holds SET expires_at_utc = :expiredAt, status = :status, released_at_utc = NULL WHERE id = :id',
            [
                'expiredAt' => (new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'status' => 'ACTIVE',
                'id' => $holdId,
            ],
        );
    }

    private function slotBookedCount($client, int $slotId): int
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $value = $entityManager->getConnection()->fetchOne('SELECT booked_count FROM appointment_slots WHERE id = ?', [$slotId]);

        return (int) $value;
    }

    private function activeBookingCountForSlot($client, int $slotId): int
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $value = $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(id) FROM appointment_bookings WHERE slot_id = ? AND status = ?',
            [$slotId, 'ACTIVE'],
        );

        return (int) $value;
    }

    /** @return array{process: resource, pipes: array<int, resource>, command: string} */
    private function startContentionWorker(string $scriptPath, int $slotId, string $username): array
    {
        $command = sprintf(
            '%s %s --slot=%d --username=%s',
            escapeshellarg((string) PHP_BINARY),
            escapeshellarg($scriptPath),
            $slotId,
            escapeshellarg($username),
        );

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
        );

        self::assertIsResource($process, sprintf('Failed to start contention worker command: %s', $command));
        self::assertIsArray($pipes);

        fclose($pipes[0]);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'command' => $command,
        ];
    }

    /** @param array{process: resource, pipes: array<int, resource>, command: string} $worker @return array<string, mixed> */
    private function awaitContentionWorker(array $worker): array
    {
        $startedAt = microtime(true);
        $timeoutSeconds = 20;

        while (true) {
            $status = proc_get_status($worker['process']);
            if (!is_array($status) || ($status['running'] ?? false) === false) {
                break;
            }

            if ((microtime(true) - $startedAt) > $timeoutSeconds) {
                proc_terminate($worker['process']);
                break;
            }

            usleep(50000);
        }

        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);

        $exitCode = proc_close($worker['process']);
        $decoded = is_string($stdout) ? json_decode(trim($stdout), true) : null;

        self::assertIsArray(
            $decoded,
            sprintf('Worker output was not valid JSON. Command: %s Exit: %d Stdout: %s Stderr: %s', $worker['command'], $exitCode, (string) $stdout, (string) $stderr),
        );

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function login($client, string $username): void
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

    private function logout($client, string $csrfToken): void
    {
        $client->request('POST', '/api/auth/logout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ], content: json_encode([], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
    }

    private function fetchCsrfToken($client): string
    {
        $client->request('GET', '/api/auth/csrf-token');
        self::assertResponseIsSuccessful();

        return (string) ($this->json($client->getResponse()->getContent())['data']['csrfToken'] ?? '');
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
