<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Security;

use Providentia\Catalog\Application\CatalogImageCipher;
use Providentia\Catalog\Application\EncryptedCatalogImage;

final class SodiumCatalogImageCipher implements CatalogImageCipher
{
    private readonly ?string $key;
    /** @var array<int, string> */
    private readonly array $readKeys;

    /**
     * @param list<array{version: int, kek: string}> $previousKeys
     */
    public function __construct(
        string $base64Key,
        private readonly int $keyVersion,
        array $previousKeys = [],
    ) {
        $decoded = base64_decode(trim($base64Key), true);
        $this->key = is_string($decoded) && strlen($decoded) === 32 ? $decoded : null;
        $readKeys = [];
        if ($this->key !== null && $this->keyVersion > 0) {
            $readKeys[$this->keyVersion] = $this->key;
        }
        foreach ($previousKeys as $entry) {
            if (! is_array($entry)) {
                throw new \InvalidArgumentException('Catalog image read keys must be structured objects.');
            }
            $version = $entry['version'] ?? null;
            $previous = isset($entry['kek']) && is_string($entry['kek'])
                ? base64_decode(trim($entry['kek']), true)
                : false;
            if (
                ! is_int($version)
                || $version < 1
                || isset($readKeys[$version])
                || ! is_string($previous)
                || strlen($previous) !== 32
            ) {
                throw new \InvalidArgumentException(
                    'Catalog image read keys must have unique positive versions and 32-byte base64 keys.',
                );
            }
            $readKeys[$version] = $previous;
        }
        $this->readKeys = $readKeys;
    }

    public function available(): bool
    {
        return $this->key !== null
            && $this->keyVersion > 0
            && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }

    public function encrypt(string $plaintext, string $associatedData): EncryptedCatalogImage
    {
        if (! $this->available() || $this->key === null) {
            throw new \RuntimeException('Catalog image encryption is unavailable.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $associatedData,
            $nonce,
            $this->key,
        );

        return new EncryptedCatalogImage($ciphertext, $nonce, $this->keyVersion);
    }

    public function decrypt(EncryptedCatalogImage $encrypted, string $associatedData): string
    {
        $key = $this->readKeys[$encrypted->keyVersion] ?? null;
        if (! is_string($key) || ! function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            throw new \RuntimeException('Catalog image decryption is unavailable.');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $encrypted->ciphertext,
            $associatedData,
            $encrypted->nonce,
            $key,
        );
        if (! is_string($plaintext)) {
            throw new \RuntimeException('Catalog image decryption failed.');
        }

        return $plaintext;
    }
}
