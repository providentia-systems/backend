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

    public function testProfileCredentialsAreBoundToTheOwnerScopedV2AssociatedData(): void
    {
        $cipher = new NativeCredentialCipher(base64_encode(str_repeat('k', 32)), 1);
        $privateScope = 'providentia-ai-profile:v2:home-a:user-1:profile-1';
        $encrypted = $cipher->encrypt('provider-secret', $privateScope);

        self::assertSame(
            'provider-secret',
            $cipher->decrypt(
                $encrypted['ciphertext'],
                $encrypted['nonce'],
                $encrypted['keyVersion'],
                $privateScope,
            ),
        );

        foreach (
            [
                // The same profile re-scoped as home-shared must not decrypt.
                'providentia-ai-profile:v2:home-a:home:profile-1',
                // Another person's scope must not decrypt.
                'providentia-ai-profile:v2:home-a:user-2:profile-1',
                // The retired v1 form must not decrypt either.
                'providentia-ai-profile:v1:home-a:profile-1',
            ] as $foreignScope
        ) {
            try {
                $cipher->decrypt(
                    $encrypted['ciphertext'],
                    $encrypted['nonce'],
                    $encrypted['keyVersion'],
                    $foreignScope,
                );
                self::fail('A profile credential decrypted outside its owner scope.');
            } catch (AiProviderException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMissingOrMalformedKekDisablesCredentialOperations(): void
    {
        $cipher = new NativeCredentialCipher('not-base64', 1);

        self::assertFalse($cipher->available());
        $this->expectException(AiProviderException::class);
        $cipher->encrypt('secret', 'associated-data');
    }
}
