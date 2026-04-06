<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;

final class LocalCaptchaService
{
    private const CHALLENGE_KEY = 'captcha_challenge';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * @return array{challengeId: string, prompt: string}
     */
    public function issueChallenge(): array
    {
        $left = random_int(1, 9);
        $right = random_int(1, 9);
        $answer = (string) ($left + $right);
        $challengeId = bin2hex(random_bytes(8));

        $session = $this->requestStack->getSession();
        if (!$session) {
            throw new \RuntimeException('Session is unavailable for captcha challenge.');
        }

        $session->set(self::CHALLENGE_KEY, [
            'id' => $challengeId,
            'answer' => $answer,
            'issuedAtUtc' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ]);

        return [
            'challengeId' => $challengeId,
            'prompt' => sprintf('What is %d + %d?', $left, $right),
        ];
    }

    public function validate(?string $challengeId, ?string $answer): bool
    {
        if ($challengeId === null || $answer === null) {
            return false;
        }

        $session = $this->requestStack->getSession();
        if (!$session) {
            return false;
        }

        $challenge = $session->get(self::CHALLENGE_KEY);
        if (!is_array($challenge)) {
            return false;
        }

        $isValid = hash_equals((string) ($challenge['id'] ?? ''), $challengeId)
            && hash_equals((string) ($challenge['answer'] ?? ''), trim($answer));

        if ($isValid) {
            $session->remove(self::CHALLENGE_KEY);
        }

        return $isValid;
    }
}
