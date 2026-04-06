<?php

declare(strict_types=1);

namespace App\Security;

final class KeyringProvider
{
    public function __construct(private readonly string $keyringPath)
    {
    }

    /**
     * @return array{activeKeyId: string, keys: array<string, string>}
     */
    public function load(): array
    {
        if (!is_file($this->keyringPath)) {
            throw new \RuntimeException(sprintf('Keyring file not found: %s', $this->keyringPath));
        }

        $raw = file_get_contents($this->keyringPath);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read keyring file.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['activeKeyId'], $decoded['keys']) || !is_array($decoded['keys'])) {
            throw new \RuntimeException('Invalid keyring format.');
        }

        /** @var array{activeKeyId: string, keys: array<string, string>} $decoded */
        return $decoded;
    }

    /**
     * @return array{keyId: string, key: string}
     */
    public function activeKey(): array
    {
        $keyring = $this->load();
        $activeKeyId = $keyring['activeKeyId'];
        $key = $keyring['keys'][$activeKeyId] ?? null;

        if (!is_string($key) || $key === '') {
            throw new \RuntimeException('Active key is missing from keyring.');
        }

        return ['keyId' => $activeKeyId, 'key' => $key];
    }

    public function keyById(string $keyId): string
    {
        $keyring = $this->load();
        $key = $keyring['keys'][$keyId] ?? null;

        if (!is_string($key) || $key === '') {
            throw new \RuntimeException(sprintf('Unknown key id: %s', $keyId));
        }

        return $key;
    }
}
