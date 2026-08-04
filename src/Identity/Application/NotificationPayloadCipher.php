<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

interface NotificationPayloadCipher
{
    /** @return array{ciphertext: string, nonce: string, keyVersion: int} */
    public function encrypt(string $plaintext, string $associatedData): array;

    public function decrypt(
        string $ciphertext,
        string $nonce,
        int $keyVersion,
        string $associatedData,
    ): string;
}
