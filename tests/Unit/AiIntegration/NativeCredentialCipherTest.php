<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Infrastructure\Security\NativeCredentialCipher;

final class NativeCredentialCipherTest extends TestCase
{
    public function testCredentialRoundTripIsBoundToHomeAndProviderAssociatedData(): void
    {
        $cipher = new NativeCredentialCipher(base64_encode(str_repeat('k', 32)), 7);
        $encrypted = $cipher->encrypt('provider-secret', 'home-a:openai');

        self::assertTrue($cipher->available());
        self::assertSame(7, $encrypted['keyVersion']);
        self::assertSame(
            'provider-secret',
            $cipher->decrypt(
                $encrypted['ciphertext'],
                $encrypted['nonce'],
                $encrypted['keyVersion'],
                'home-a:openai',
            ),
        );

        $this->expectException(AiProviderException::class);
        $cipher->decrypt(
            $encrypted['ciphertext'],
            $encrypted['nonce'],
            $encrypted['keyVersion'],
            'home-b:openai',
        );
    }

    public function testMissingOrMalformedKekDisablesCredentialOperations(): void
    {
        $cipher = new NativeCredentialCipher('not-base64', 1);

        self::assertFalse($cipher->available());
        $this->expectException(AiProviderException::class);
        $cipher->encrypt('secret', 'associated-data');
    }
}
