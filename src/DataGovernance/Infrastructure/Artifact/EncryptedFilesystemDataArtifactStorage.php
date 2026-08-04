<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Infrastructure\Artifact;

use Providentia\DataGovernance\Application\DataArtifact;
use Providentia\DataGovernance\Application\DataArtifactStorage;

final readonly class EncryptedFilesystemDataArtifactStorage implements DataArtifactStorage
{
    private string $key;

    public function __construct(private string $root, string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \RuntimeException('The data-export encryption key is unavailable.');
        }
        $this->key = $key;
    }

    public function store(string $requestId, string $json): DataArtifact
    {
        $this->identifier($requestId);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $json,
            $this->associatedData($requestId),
            $nonce,
            $this->key,
        );
        $reference = substr(hash('sha256', $requestId), 0, 16) . '/' . $requestId . '.export';
        $path = $this->path($reference);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('The encrypted export directory could not be prepared.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temporary, $ciphertext, LOCK_EX) === false || ! rename($temporary, $path)) {
                throw new \RuntimeException('The encrypted export artifact could not be stored.');
            }
            chmod($path, 0600);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return new DataArtifact(
            $reference,
            base64_encode($nonce),
            hash('sha256', $json),
            strlen($json),
        );
    }

    public function read(string $requestId, DataArtifact $artifact): string
    {
        $this->identifier($requestId);
        $ciphertext = file_get_contents($this->path($artifact->reference));
        $nonce = base64_decode($artifact->nonce, true);
        if (! is_string($ciphertext) || ! is_string($nonce)) {
            throw new \RuntimeException('The encrypted export artifact is unavailable.');
        }
        $json = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $this->associatedData($requestId),
            $nonce,
            $this->key,
        );
        if (! is_string($json) || ! hash_equals($artifact->sha256, hash('sha256', $json))) {
            throw new \RuntimeException('The encrypted export artifact failed authentication.');
        }

        return $json;
    }

    public function delete(DataArtifact $artifact): void
    {
        $path = $this->path($artifact->reference);
        if (is_file($path) && ! unlink($path)) {
            throw new \RuntimeException('The encrypted export artifact could not be deleted.');
        }
    }

    private function identifier(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Data-export identifier rejected.');
        }
    }

    private function associatedData(string $requestId): string
    {
        return 'providentia-data-export:v1:' . $requestId;
    }

    private function path(string $reference): string
    {
        if (preg_match('#^[a-f0-9]{16}/[A-Za-z0-9-]{1,64}\.export$#', $reference) !== 1) {
            throw new \InvalidArgumentException('Data-export reference rejected.');
        }

        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $reference;
    }
}
