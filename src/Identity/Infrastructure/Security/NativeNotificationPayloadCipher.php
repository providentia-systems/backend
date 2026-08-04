<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Security;

use Providentia\Identity\Application\NotificationPayloadCipher;
use RuntimeException;

final class NativeNotificationPayloadCipher implements NotificationPayloadCipher
{
    private readonly string $key;

    public function __construct(string $encodedKey, private readonly int $keyVersion)
    {
        $key = base64_decode($encodedKey, true);
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('NOTIFICATION_PAYLOAD_KEK must contain exactly 32 base64-encoded bytes.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext, string $associatedData): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $associatedData,
            $nonce,
            $this->key,
        );

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'keyVersion' => $this->keyVersion,
        ];
    }

    public function decrypt(
        string $ciphertext,
        string $nonce,
        int $keyVersion,
        string $associatedData,
    ): string {
        if ($keyVersion !== $this->keyVersion) {
            throw new RuntimeException('The notification payload key version is unavailable.');
        }
        $decodedCiphertext = base64_decode($ciphertext, true);
        $decodedNonce = base64_decode($nonce, true);
        if (! is_string($decodedCiphertext) || ! is_string($decodedNonce)) {
            throw new RuntimeException('The notification payload encoding is invalid.');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $decodedCiphertext,
            $associatedData,
            $decodedNonce,
            $this->key,
        );
        if (! is_string($plaintext)) {
            throw new RuntimeException('The notification payload failed authentication.');
        }

        return $plaintext;
    }
}
