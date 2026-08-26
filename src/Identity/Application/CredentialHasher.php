<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

interface CredentialHasher
{
    public function hashToken(string $token): string;
}
