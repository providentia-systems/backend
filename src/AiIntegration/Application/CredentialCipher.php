<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

interface CredentialCipher
{
    public function available(): bool;

    /** @return array{ciphertext: string, nonce: string, keyVersion: int} */
    public function encrypt(string $plaintext, string $associatedData): array;

    public function decrypt(
        string $ciphertext,
        string $nonce,
        int $keyVersion,
        string $associatedData,
    ): string;
}
