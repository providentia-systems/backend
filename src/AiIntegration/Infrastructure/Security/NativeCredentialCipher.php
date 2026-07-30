<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Security;

use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Application\CredentialCipher;

final class NativeCredentialCipher implements CredentialCipher
{
    private readonly ?string $key;

    public function __construct(string $base64Key, private readonly int $keyVersion)
    {
        $decoded = base64_decode(trim($base64Key), true);
        $this->key = is_string($decoded)
            && strlen($decoded) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
            ? $decoded
            : null;
    }

    public function available(): bool
    {
        return $this->key !== null;
    }

    public function encrypt(string $plaintext, string $associatedData): array
    {
        if ($this->key === null) {
            throw new AiProviderException(
                'credential_encryption_unavailable',
                'Server-side credential encryption is not configured.',
            );
        }
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
        if ($this->key === null || $keyVersion !== $this->keyVersion) {
            throw new AiProviderException(
                'credential_decryption_unavailable',
                'The stored provider credential requires administrative rotation.',
            );
        }
        $decodedCiphertext = base64_decode($ciphertext, true);
        $decodedNonce = base64_decode($nonce, true);
        if (! is_string($decodedCiphertext) || ! is_string($decodedNonce)) {
            throw new AiProviderException(
                'credential_integrity_failed',
                'The stored provider credential failed integrity validation.',
            );
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $decodedCiphertext,
            $associatedData,
            $decodedNonce,
            $this->key,
        );
        if (! is_string($plaintext)) {
            throw new AiProviderException(
                'credential_integrity_failed',
                'The stored provider credential failed integrity validation.',
            );
        }

        return $plaintext;
    }
}
