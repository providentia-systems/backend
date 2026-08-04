<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use PHPUnit\Framework\TestCase;
use Providentia\Identity\Infrastructure\Security\NativeNotificationPayloadCipher;
use RuntimeException;

final class NativeNotificationPayloadCipherTest extends TestCase
{
    public function testRoundTripAuthenticatesAssociatedData(): void
    {
        $cipher = new NativeNotificationPayloadCipher(base64_encode(str_repeat('k', 32)), 3);
        $encrypted = $cipher->encrypt('{"token":"secret"}', 'message-id\0magic-link\0user@example.test');

        self::assertNotSame('{"token":"secret"}', $encrypted['ciphertext']);
        self::assertSame(3, $encrypted['keyVersion']);
        self::assertSame(
            '{"token":"secret"}',
            $cipher->decrypt(
                $encrypted['ciphertext'],
                $encrypted['nonce'],
                $encrypted['keyVersion'],
                'message-id\0magic-link\0user@example.test',
            ),
        );
    }

    public function testWrongAssociatedDataFailsAuthentication(): void
    {
        $cipher = new NativeNotificationPayloadCipher(base64_encode(str_repeat('k', 32)), 1);
        $encrypted = $cipher->encrypt('secret', 'correct');

        $this->expectException(RuntimeException::class);
        $cipher->decrypt(
            $encrypted['ciphertext'],
            $encrypted['nonce'],
            $encrypted['keyVersion'],
            'wrong',
        );
    }
}
