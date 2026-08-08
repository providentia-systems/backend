<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Http\IdentityHandler;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\UuidGenerator;

final class IdentityHandlerTest extends TestCase
{
    private const USER_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const SESSION_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const DEVICE_ID = '01912345-6789-7abc-adef-0123456789ab';

    public function testWebLoginReturnsCsrfButKeepsBearerCredentialsOutOfJson(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('findUserByEmail')->willReturn([
            'id' => self::USER_ID,
            'password_hash' => 'stored-password-hash',
            'locked_until' => null,
            'email_verified_at' => '2026-07-30 10:00:00',
            'status' => 'active',
        ]);
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('verifyPassword')->willReturn(true);
        $hasher->method('hashToken')->willReturnCallback(
            static fn (string $token): string => 'hash:' . $token,
        );
        $tokens = $this->createStub(SecureTokenGenerator::class);
        $tokens->method('generate')->willReturnOnConsecutiveCalls(
            'access-token-secret',
            'refresh-token-secret',
            'csrf-token-value',
        );
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn(self::SESSION_ID);
        $authentication = new AuthenticationService(
            $store,
            $hasher,
            $this->createStub(AccountNotificationSender::class),
            $ids,
            new IdentityFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            new IdentityTransactionManager(),
            $tokens,
            900,
            2592000,
        );
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://app.example.test/api/v1/auth/login'),
            'POST',
            'php://memory',
        ))->withParsedBody([
            'email' => 'user@example.test',
            'password' => 'Valid-password-123',
            'deviceId' => self::DEVICE_ID,
            'deviceName' => 'Web browser',
            'platform' => 'web',
            'transport' => 'web',
        ]);

        $response = (new IdentityHandler($authentication, 'login', false))->handle($request);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('csrf-token-value', $body['csrfToken']);
        self::assertArrayNotHasKey('accessToken', $body);
        self::assertArrayNotHasKey('refreshToken', $body);
        self::assertSame('secure-cookie', $body['transport']);
        self::assertCount(3, $response->getHeader('Set-Cookie'));
    }
}
