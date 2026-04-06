<?php

declare(strict_types=1);

namespace App\Security;

final class FieldEncryptionService
{
    public function __construct(private readonly KeyringProvider $keyringProvider)
    {
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string, auth_tag: string}
     */
    public function encrypt(string $plaintext): array
    {
        $active = $this->keyringProvider->activeKey();
        $binaryKey = base64_decode($active['key'], true);
        if ($binaryKey === false || strlen($binaryKey) !== 32) {
            throw new \RuntimeException('Invalid AES-256 key material.');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $binaryKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return [
            'key_id' => $active['keyId'],
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
            'auth_tag' => base64_encode($tag),
        ];
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string, auth_tag: string} $payload
     */
    public function decrypt(array $payload): string
    {
        $binaryKey = base64_decode($this->keyringProvider->keyById($payload['key_id']), true);
        if ($binaryKey === false || strlen($binaryKey) !== 32) {
            throw new \RuntimeException('Invalid AES-256 key material.');
        }

        $nonce = base64_decode($payload['nonce'], true);
        $ciphertext = base64_decode($payload['ciphertext'], true);
        $tag = base64_decode($payload['auth_tag'], true);

        if ($nonce === false || $ciphertext === false || $tag === false) {
            throw new \RuntimeException('Encrypted payload is invalid.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $binaryKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }
}
