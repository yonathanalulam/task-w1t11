<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * Shared base for real-HTTP smoke tests against the composed Docker stack.
 *
 * Concrete subclasses exercise specific endpoint families. All subclasses:
 *   - skip cleanly when SMOKE_BASE_URL is unset or unreachable
 *   - speak HTTP over stream_context_create (no ext-curl dependency)
 *   - maintain a per-test cookie jar so session + CSRF flows round-trip
 *     exactly like a real browser client
 */
abstract class AbstractHttpSmokeTestCase extends TestCase
{
    protected string $baseUrl = '';

    /** @var array<string, string> */
    protected array $cookieJar = [];

    protected function setUp(): void
    {
        $baseUrl = (string) (getenv('SMOKE_BASE_URL') ?: '');
        if ($baseUrl === '') {
            self::markTestSkipped('SMOKE_BASE_URL is not set. Run scripts/dev/real_http_smoke.sh to exercise this layer.');
        }

        $baseUrl = rtrim($baseUrl, '/');

        if (!$this->isReachable($baseUrl . '/api/health/live')) {
            self::markTestSkipped(sprintf('SMOKE_BASE_URL %s is not reachable from the test container.', $baseUrl));
        }

        $this->baseUrl = $baseUrl;
        $this->cookieJar = [];
    }

    protected function loginAs(string $username): string
    {
        $login = $this->request('POST', '/api/auth/login', [
            'json' => [
                'username' => $username,
                'password' => $this->devPassword(),
            ],
        ]);
        self::assertSame(200, $login['status'], sprintf('login as %s failed: %s', $username, $login['body']));

        $csrfResponse = $this->request('GET', '/api/auth/csrf-token');
        self::assertSame(200, $csrfResponse['status']);

        $token = (string) ($this->json($csrfResponse['body'])['data']['csrfToken'] ?? '');
        self::assertNotSame('', $token, 'CSRF token must be issued on the active session.');

        return $token;
    }

    protected function devPassword(): string
    {
        $fromEnv = (string) (getenv('DEV_BOOTSTRAP_PASSWORD') ?: '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $runtimeEnvPath = '/workspace/runtime/dev/runtime.env';
        if (!is_readable($runtimeEnvPath)) {
            self::markTestSkipped('DEV_BOOTSTRAP_PASSWORD is not available for smoke test.');
        }

        $raw = (string) @file_get_contents($runtimeEnvPath);
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^DEV_BOOTSTRAP_PASSWORD=(.*)$/', trim($line), $matches) === 1) {
                return $matches[1];
            }
        }

        self::markTestSkipped('DEV_BOOTSTRAP_PASSWORD missing from runtime.env.');
    }

    /**
     * @param array{json?: mixed, body?: string, headers?: array<string, string>} $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected function request(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl . $path;
        $headers = ['Accept: application/json'];

        $body = '';
        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($body);
        } elseif (isset($options['body']) && is_string($options['body'])) {
            $body = $options['body'];
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        foreach (($options['headers'] ?? []) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        if ($this->cookieJar !== []) {
            $pairs = [];
            foreach ($this->cookieJar as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 15,
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            self::fail(sprintf('Failed to open HTTP stream to %s %s', $method, $url));
        }

        $meta = stream_get_meta_data($stream);
        $responseBody = (string) stream_get_contents($stream);
        fclose($stream);

        /** @var array<int, string> $rawHeaders */
        $rawHeaders = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];

        $status = 0;
        $normalized = [];
        foreach ($rawHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
                continue;
            }

            $parts = explode(':', $header, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            $normalized[$name] = $value;

            if ($name === 'set-cookie') {
                $cookieParts = explode(';', $value, 2);
                if ($cookieParts !== [] && $cookieParts[0] !== '') {
                    $kv = explode('=', $cookieParts[0], 2);
                    if (count($kv) === 2) {
                        $this->cookieJar[trim($kv[0])] = trim($kv[1]);
                    }
                }
            }
        }

        return [
            'status' => $status,
            'headers' => $normalized,
            'body' => $responseBody,
        ];
    }

    /**
     * Multipart variant of request() for file-upload endpoints.
     *
     * @param array<string, string>                                                   $fields
     * @param array<string, array{filename: string, contentType: string, content: string}> $files
     * @param array<string, string>                                                   $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected function requestMultipart(
        string $method,
        string $path,
        array $fields,
        array $files,
        array $headers = [],
    ): array {
        $boundary = '----Boundary' . bin2hex(random_bytes(12));
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= sprintf("Content-Disposition: form-data; name=\"%s\"\r\n\r\n", $name);
            $body .= $value . "\r\n";
        }

        foreach ($files as $name => $file) {
            $body .= "--{$boundary}\r\n";
            $body .= sprintf(
                "Content-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\n",
                $name,
                $file['filename'],
            );
            $body .= sprintf("Content-Type: %s\r\n\r\n", $file['contentType']);
            $body .= $file['content'] . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return $this->request($method, $path, [
            'body' => $body,
            'headers' => array_merge($headers, [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    protected function json(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isReachable(string $url): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return false;
        }

        fclose($stream);

        return true;
    }
}
