<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthSecurityHardeningTest extends WebTestCase
{
    public function testCaptchaRequiredAfterThreeFailedAttemptsAndLockoutAfterFive(): void
    {
        $client = static::createClient();

        $username = sprintf('lockout_user_%s', bin2hex(random_bytes(4)));
        $password = 'ValidPassword123!';

        $client->request('POST', '/api/auth/register', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $username,
            'password' => $password,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $client->request('POST', '/api/auth/login', server: [
                'CONTENT_TYPE' => 'application/json',
            ], content: json_encode([
                'username' => $username,
                'password' => 'WrongPassword123!',
            ], JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(401);
            self::assertSame('INVALID_CREDENTIALS', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
        }

        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $username,
            'password' => 'WrongPassword123!',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('VALIDATION_ERROR', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);

        $challengeOne = $this->issueCaptchaChallenge($client);
        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $username,
            'password' => 'WrongPassword123!',
            'captchaChallengeId' => $challengeOne['challengeId'],
            'captchaResponse' => $challengeOne['answer'],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);
        self::assertSame('INVALID_CREDENTIALS', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);

        $challengeTwo = $this->issueCaptchaChallenge($client);
        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $username,
            'password' => 'WrongPassword123!',
            'captchaChallengeId' => $challengeTwo['challengeId'],
            'captchaResponse' => $challengeTwo['answer'],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(423);
        self::assertSame('ACCOUNT_LOCKED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);

        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => $username,
            'password' => $password,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(423);
        self::assertSame('ACCOUNT_LOCKED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
    }

    public function testMutatingApiRejectsMissingAndInvalidCsrfTokens(): void
    {
        $client = static::createClient();

        $devPassword = getenv('DEV_BOOTSTRAP_PASSWORD');
        self::assertIsString($devPassword);
        self::assertNotSame('', $devPassword);

        $client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'username' => 'system_admin',
            'password' => $devPassword,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/auth/logout', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('ACCESS_DENIED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);

        $client->request('POST', '/api/auth/logout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'invalid-csrf-token',
        ], content: json_encode([], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('ACCESS_DENIED', $this->json($client->getResponse()->getContent())['error']['code'] ?? null);
    }

    /** @return array{challengeId: string, answer: string} */
    private function issueCaptchaChallenge($client): array
    {
        $client->request('GET', '/api/auth/captcha');
        self::assertResponseIsSuccessful();

        $payload = $this->json($client->getResponse()->getContent());
        $challengeId = (string) ($payload['data']['challengeId'] ?? '');
        $prompt = (string) ($payload['data']['prompt'] ?? '');

        self::assertNotSame('', $challengeId);
        self::assertMatchesRegularExpression('/What is (\d+) \+ (\d+)\?/', $prompt);

        preg_match('/What is (\d+) \+ (\d+)\?/', $prompt, $matches);
        $answer = (string) (((int) ($matches[1] ?? 0)) + ((int) ($matches[2] ?? 0)));

        return [
            'challengeId' => $challengeId,
            'answer' => $answer,
        ];
    }

    /** @return array<string, mixed> */
    private function json(string|false $content): array
    {
        return is_string($content) ? (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
