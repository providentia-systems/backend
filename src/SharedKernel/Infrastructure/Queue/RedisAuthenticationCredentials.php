<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Queue;

final class RedisAuthenticationCredentials
{
    /**
     * @param array{user?: string, pass: string} $parts
     * @return string|array{string, string}
     */
    public static function fromUrlParts(array $parts): string|array
    {
        $username = rawurldecode($parts['user'] ?? '');
        $password = rawurldecode($parts['pass']);

        return $username === '' ? $password : [$username, $password];
    }
}
