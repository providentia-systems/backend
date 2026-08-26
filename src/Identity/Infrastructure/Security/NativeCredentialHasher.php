<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Security;

use Providentia\Identity\Application\CredentialHasher;
use RuntimeException;

final class NativeCredentialHasher implements CredentialHasher
{
    public function __construct(private readonly string $pepper)
    {
        if (strlen($this->pepper) < 16) {
            throw new RuntimeException('AUTH_TOKEN_PEPPER must contain at least 16 characters.');
        }
    }

    public function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, $this->pepper);
    }
}
