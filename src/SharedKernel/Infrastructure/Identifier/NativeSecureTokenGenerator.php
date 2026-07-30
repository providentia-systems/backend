<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Identifier;

use InvalidArgumentException;
use Providentia\SharedKernel\Application\SecureTokenGenerator;

final class NativeSecureTokenGenerator implements SecureTokenGenerator
{
    public function generate(int $bytes = 32): string
    {
        if ($bytes < 16 || $bytes > 1024) {
            throw new InvalidArgumentException('Secure tokens require 16 to 1024 random bytes.');
        }

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
