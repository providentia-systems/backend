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
        self::assertSame('web', $body['transport']);
        self::assertCount(3, $response->getHeader('Set-Cookie'));
        $cookies = implode("\n", $response->getHeader('Set-Cookie'));
        self::assertStringContainsString('Max-Age=2592000', $cookies);
        self::assertStringContainsString('Expires=', $cookies);
        self::assertStringContainsString('Secure', $cookies);
    }

    public function testNativeLogoutUsesRefreshPossessionProofAndClearsCookies(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::once())->method('revokeSessionByRefreshHash')
            ->with('hash:native-refresh', self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(true);
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/api/v1/auth/logout'),
            'POST',
        ))->withParsedBody(['refreshToken' => 'native-refresh']);

        $response = (new IdentityHandler($this->authentication($store), 'logout', false))->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertCount(3, $response->getHeader('Set-Cookie'));
    }

    public function testInvalidNativeLogoutProofReturnsUnauthorizedAndClearsCookies(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('revokeSessionByRefreshHash')->willReturn(false);
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/api/v1/auth/logout'),
            'POST',
        ))->withParsedBody(['refreshToken' => 'invalid-refresh']);

        $response = (new IdentityHandler($this->authentication($store), 'logout', false))->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertCount(3, $response->getHeader('Set-Cookie'));
    }

    public function testWebLogoutUsesRefreshCookieAndMatchingCsrfProof(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::once())->method('revokeSessionByRefreshProof')->with(
            'hash:web-refresh',
            'hash:web-csrf',
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn(true);
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/api/v1/auth/logout'),
            'POST',
            'php://memory',
            ['X-CSRF-Token' => ['web-csrf']],
        ))->withCookieParams([
            'providentia_refresh' => 'web-refresh',
            'providentia_csrf' => 'web-csrf',
        ]);

        $response = (new IdentityHandler($this->authentication($store), 'logout', false))->handle($request);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testWebLogoutRejectsMismatchedCsrfButStillClearsCookies(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::never())->method('revokeSessionByRefreshProof');
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/api/v1/auth/logout'),
            'POST',
            'php://memory',
            ['X-CSRF-Token' => ['wrong-csrf']],
        ))->withCookieParams([
            'providentia_refresh' => 'web-refresh',
            'providentia_csrf' => 'web-csrf',
        ]);

        $response = (new IdentityHandler($this->authentication($store), 'logout', false))->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(3, $response->getHeader('Set-Cookie'));
    }

    private function authentication(IdentityStore $store): AuthenticationService
    {
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')->willReturnCallback(
            static fn (string $token): string => 'hash:' . $token,
        );

        return new AuthenticationService(
            $store,
            $hasher,
            $this->createStub(AccountNotificationSender::class),
            $this->createStub(UuidGenerator::class),
            new IdentityFixedClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00')),
            new IdentityTransactionManager(),
            $this->createStub(SecureTokenGenerator::class),
            900,
            2592000,
        );
    }
}
