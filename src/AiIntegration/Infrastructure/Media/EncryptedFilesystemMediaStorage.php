<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Media;

use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Application\Media\EncryptedMediaObject;
use Providentia\AiIntegration\Application\Media\MediaStorage;

final readonly class EncryptedFilesystemMediaStorage implements MediaStorage
{
    private string $key;

    public function __construct(
        private string $root,
        string $base64Key,
        private int $keyVersion,
    ) {
        $decoded = base64_decode($base64Key, true);
        if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new AiProviderException(
                'media_encryption_unavailable',
                'The private media encryption key is unavailable.',
            );
        }
        $this->key = $decoded;
    }

    public function store(string $homeId, string $assetId, string $bytes): EncryptedMediaObject
    {
        $this->identifier($homeId);
        $this->identifier($assetId);
        $objectKey = substr(hash('sha256', $homeId), 0, 16) . '/' . $assetId . '.media';
        $path = $this->path($objectKey);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new AiProviderException('media_storage_failed', 'Private media storage could not be prepared.');
        }

        $dataKey = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dataKey);
        $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
            $state,
            $bytes,
            $this->associatedData($homeId, $assetId),
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
        );
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $dataKey,
            $this->associatedData($homeId, $assetId),
            $nonce,
            $this->key,
        );
        sodium_memzero($dataKey);

        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $written = file_put_contents($temporary, $header . $ciphertext, LOCK_EX);
            if ($written === false || ! rename($temporary, $path)) {
                throw new AiProviderException('media_storage_failed', 'Private media could not be stored.');
            }
            chmod($path, 0600);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return new EncryptedMediaObject(
            $objectKey,
            base64_encode($wrapped),
            base64_encode($nonce),
            $this->keyVersion,
            hash('sha256', $bytes),
            strlen($bytes),
        );
    }

    public function read(string $homeId, string $assetId, EncryptedMediaObject $object): string
    {
        $this->identifier($homeId);
        $this->identifier($assetId);
        $path = $this->path($object->objectKey);
        $payload = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($payload)) {
            throw new AiProviderException('media_not_found', 'The private media object is unavailable.');
        }
        $headerBytes = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        if (strlen($payload) <= $headerBytes) {
            throw new AiProviderException('media_tampered', 'The private media object failed authentication.');
        }
        $wrapped = base64_decode($object->wrappedKey, true);
        $nonce = base64_decode($object->wrapNonce, true);
        if (! is_string($wrapped) || ! is_string($nonce)) {
            throw new AiProviderException('media_tampered', 'The private media key failed authentication.');
        }
        $dataKey = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $wrapped,
            $this->associatedData($homeId, $assetId),
            $nonce,
            $this->key,
        );
        if (! is_string($dataKey)) {
            throw new AiProviderException('media_tampered', 'The private media key failed authentication.');
        }
        $header = substr($payload, 0, $headerBytes);
        $ciphertext = substr($payload, $headerBytes);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $dataKey);
        sodium_memzero($dataKey);
        $opened = sodium_crypto_secretstream_xchacha20poly1305_pull(
            $state,
            $ciphertext,
            $this->associatedData($homeId, $assetId),
        );
        if ($opened === false || $opened[1] !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            throw new AiProviderException('media_tampered', 'The private media object failed authentication.');
        }
        if (! hash_equals($object->sha256, hash('sha256', $opened[0]))) {
            throw new AiProviderException('media_tampered', 'The private media digest did not match.');
        }

        return $opened[0];
    }

    public function delete(EncryptedMediaObject $object): void
    {
        $path = $this->path($object->objectKey);
        if (is_file($path) && ! unlink($path)) {
            throw new AiProviderException('media_delete_failed', 'The private media object could not be deleted.');
        }
    }

    private function associatedData(string $homeId, string $assetId): string
    {
        return 'providentia-media:v1:' . $homeId . ':' . $assetId;
    }

    private function identifier(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $value) !== 1) {
            throw new AiProviderException('media_identifier_rejected', 'A private media identifier was rejected.');
        }
    }

    private function path(string $objectKey): string
    {
        if (preg_match('#^[a-f0-9]{16}/[A-Za-z0-9-]{1,64}\.media$#', $objectKey) !== 1) {
            throw new AiProviderException('media_identifier_rejected', 'A private media object key was rejected.');
        }

        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $objectKey;
    }
}
