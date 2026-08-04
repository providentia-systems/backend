<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Infrastructure\Queue\RedisAuthenticationCredentials;

final class RedisAuthenticationCredentialsTest extends TestCase
{
    public function testPasswordOnlyDsnUsesLegacyRedisAuthentication(): void
    {
        self::assertSame(
            'secret',
            RedisAuthenticationCredentials::fromUrlParts(['user' => '', 'pass' => 'secret']),
        );
    }

    public function testNamedUserDsnUsesAclAuthentication(): void
    {
        self::assertSame(
            ['application', 'encoded secret'],
            RedisAuthenticationCredentials::fromUrlParts([
                'user' => 'application',
                'pass' => 'encoded%20secret',
            ]),
        );
    }
}
