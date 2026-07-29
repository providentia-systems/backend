<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

interface CredentialHasher
{
    public function hashPassword(string $password): string;

    public function verifyPassword(string $password, string $hash): bool;

    public function hashToken(string $token): string;
}
