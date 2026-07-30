<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use PHPUnit\Framework\TestCase;
use Providentia\Identity\Infrastructure\Security\NativeCredentialHasher;
use RuntimeException;

final class NativeCredentialHasherTest extends TestCase
{
    public function testPasswordHashRoundTripUsesNativeVerification(): void
    {
        $hasher = new NativeCredentialHasher(str_repeat('p', 32));
        $hash = $hasher->hashPassword('Correct horse battery staple 42');

        self::assertTrue($hasher->verifyPassword('Correct horse battery staple 42', $hash));
        self::assertFalse($hasher->verifyPassword('incorrect', $hash));
        self::assertStringStartsWith('$argon2id$', $hash);
    }

    public function testTokenHashIsDeterministicKeyedDigest(): void
    {
        $pepper = str_repeat('p', 32);
        $hasher = new NativeCredentialHasher($pepper);

        self::assertSame(
            hash_hmac('sha256', 'opaque-token', $pepper),
            $hasher->hashToken('opaque-token'),
        );
        self::assertNotSame(
            $hasher->hashToken('opaque-token'),
            (new NativeCredentialHasher(str_repeat('q', 32)))->hashToken('opaque-token'),
        );
    }

    public function testShortPepperFailsFast(): void
    {
        $this->expectException(RuntimeException::class);
        new NativeCredentialHasher('short');
    }
}
