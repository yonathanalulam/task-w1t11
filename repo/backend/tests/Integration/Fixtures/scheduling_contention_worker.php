<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

require dirname(__DIR__, 2) . '/bootstrap.php';

$_SERVER['KERNEL_CLASS'] = Kernel::class;
$_ENV['KERNEL_CLASS'] = Kernel::class;

final class SchedulingContentionWorkerClient extends WebTestCase
{
    public static function buildClient(): KernelBrowser
    {
        return static::createClient();
    }
}

$slotId = null;
$username = null;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--slot=')) {
        $slotId = (int) substr($argument, strlen('--slot='));
        continue;
    }

    if (str_starts_with($argument, '--username=')) {
        $username = substr($argument, strlen('--username='));
    }
}

if (!is_int($slotId) || $slotId <= 0 || !is_string($username) || trim($username) === '') {
    fwrite(STDOUT, json_encode([
        'status' => 'FATAL',
        'message' => 'Missing required --slot and --username arguments.',
    ], JSON_THROW_ON_ERROR));
    exit(1);
}

$password = getenv('DEV_BOOTSTRAP_PASSWORD');
if (!is_string($password) || $password === '') {
    fwrite(STDOUT, json_encode([
        'status' => 'FATAL',
        'message' => 'DEV_BOOTSTRAP_PASSWORD is required for contention worker.',
        'username' => $username,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}

$client = SchedulingContentionWorkerClient::buildClient();

$client->request('POST', '/api/auth/login', server: [
    'CONTENT_TYPE' => 'application/json',
], content: json_encode([
    'username' => $username,
    'password' => $password,
], JSON_THROW_ON_ERROR));

if ($client->getResponse()->getStatusCode() !== 200) {
    fwrite(STDOUT, json_encode([
        'status' => 'ERROR',
        'code' => 'LOGIN_FAILED',
        'username' => $username,
    ], JSON_THROW_ON_ERROR));
    exit(0);
}

$client->request('GET', '/api/auth/csrf-token');
$csrfPayload = is_string($client->getResponse()->getContent())
    ? json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)
    : [];
$csrfToken = (string) ($csrfPayload['data']['csrfToken'] ?? '');

$client->request('POST', sprintf('/api/scheduling/slots/%d/hold', $slotId), server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CSRF_TOKEN' => $csrfToken,
], content: json_encode([], JSON_THROW_ON_ERROR));

$holdStatusCode = $client->getResponse()->getStatusCode();
$holdPayload = is_string($client->getResponse()->getContent())
    ? json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)
    : [];

if ($holdStatusCode !== 201) {
    fwrite(STDOUT, json_encode([
        'status' => 'ERROR',
        'code' => $holdPayload['error']['code'] ?? 'UNKNOWN_HOLD_FAILURE',
        'username' => $username,
    ], JSON_THROW_ON_ERROR));
    exit(0);
}

$holdId = (int) ($holdPayload['data']['holdId'] ?? 0);
if ($holdId <= 0) {
    fwrite(STDOUT, json_encode([
        'status' => 'FATAL',
        'message' => 'Hold creation succeeded but holdId was missing.',
        'username' => $username,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}

$client->request('POST', sprintf('/api/scheduling/holds/%d/book', $holdId), server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CSRF_TOKEN' => $csrfToken,
], content: json_encode([], JSON_THROW_ON_ERROR));

$bookStatusCode = $client->getResponse()->getStatusCode();
$bookPayload = is_string($client->getResponse()->getContent())
    ? json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)
    : [];

if ($bookStatusCode === 201) {
    fwrite(STDOUT, json_encode([
        'status' => 'BOOKED',
        'holdId' => $holdId,
        'bookingId' => $bookPayload['data']['booking']['id'] ?? null,
        'username' => $username,
    ], JSON_THROW_ON_ERROR));
    exit(0);
}

fwrite(STDOUT, json_encode([
    'status' => 'ERROR',
    'code' => $bookPayload['error']['code'] ?? 'UNKNOWN_BOOK_FAILURE',
    'holdId' => $holdId,
    'username' => $username,
], JSON_THROW_ON_ERROR));
exit(0);
