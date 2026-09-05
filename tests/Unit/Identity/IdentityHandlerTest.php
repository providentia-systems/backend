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
    public function testWebRefreshReturnsCsrfButKeepsBearerCredentialsOutOfJson(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('findSessionByRefreshHash')
            ->willReturn(
                [
                'id' => self::SESSION_ID,
                'user_id' => self::USER_ID,
                'device_id' => self::DEVICE_ID,
                'installation_id' => self::DEVICE_ID,
                'refresh_token_hash' => 'hash:current-refresh',
                'transport' => 'web',
                'refresh_idle_ttl_seconds' => 2592000,
                'active_home_id' => null,
                ],
            );
        $store->method('rotateSession')
            ->willReturn(true);
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')
            ->willReturnCallback(
                static fn(string $token): string => 'hash:' . $token,
            );
        $tokens = $this->createStub(SecureTokenGenerator::class);
        $tokens->method('generate')
            ->willReturnOnConsecutiveCalls(
                'access-token-secret',
                'refresh-token-secret',
                'csrf-token-value',
            );
        $authentication = new AuthenticationService(
            $store,
            $hasher,
            $this->createStub(UuidGenerator::class),
            new IdentityFixedClock(
                new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
            ),
            $tokens,
            900,
            2592000,
            0,
            0,
            \ProvidentiaTest\Support\AccessFixture::create(),
        );
        $request = new ServerRequest(
            [],
            [],
            new Uri('https://app.example.test/api/v1/auth/refresh'),
            'POST',
            'php://memory',
        )->withCookieParams(
            ['providentia_refresh' => 'current-refresh'],
        );
        $response = new IdentityHandler($authentication, 'refresh')->handle($request);
        /** @var array<string, mixed> $body */
        $body = json_decode(
            (string) $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('csrf-token-value', $body['csrfToken']);
        self::assertArrayNotHasKey('accessToken', $body);
        self::assertArrayNotHasKey('refreshToken', $body);
        self::assertSame('web', $body['transport']);
        self::assertSame(self::DEVICE_ID, $body['installationId']);
        self::assertCount(3, $response->getHeader('Set-Cookie'));
        $cookies = implode("\n", $response->getHeader('Set-Cookie'));
        self::assertStringContainsString('Max-Age=2592000', $cookies);
        self::assertStringContainsString('Expires=', $cookies);
        self::assertStringContainsString('Secure', $cookies);
    }

    public function testNativeLogoutUsesRefreshPossessionProofAndClearsCookies(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::once())
            ->method('revokeSessionByRefreshHash')
            ->with(
                'hash:native-refresh',
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                true,
            );
        $request = new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/api/v1/auth/logout'),
            'POST',
        )->withParsedBody(
            ['refreshToken' => 'native-refresh'],
        );
        $response = new IdentityHandler($this->authentication($store), 'logout')->handle($request);
        self::assertSame(204, $response->getStatusCode());
        self::assertCount(3, $response->getHeader('Set-Cookie'));
    }

    public function testInvalidNativeLogoutProofReturnsUnauthorizedAndClearsCookies(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('revokeSessionByRefreshHash')
            ->willReturn(false);
        $request = new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/api/v1/auth/logout'),
            'POST',
        )->withParsedBody(
            ['refreshToken' => 'invalid-refresh'],
        );
        $response = new IdentityHandler($this->authentication($store), 'logout')->handle($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertCount(3, $response->getHeader('Set-Cookie'));
    }

    public function testWebLogoutUsesRefreshCookieAndMatchingCsrfProof(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::once())
            ->method('revokeSessionByRefreshProof')
            ->with(
                'hash:web-refresh',
                'hash:web-csrf',
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                true,
            );
        $request = new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/api/v1/auth/logout'),
            'POST',
            'php://memory',
            ['X-CSRF-Token' => ['web-csrf']],
        )->withCookieParams(
            [
                'providentia_refresh' => 'web-refresh',
                'providentia_csrf' => 'web-csrf',
            ],
        );
        $response = new IdentityHandler($this->authentication($store), 'logout')->handle($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testWebLogoutRejectsMismatchedCsrfButStillClearsCookies(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::never())
            ->method('revokeSessionByRefreshProof');
        $request = new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/api/v1/auth/logout'),
            'POST',
            'php://memory',
            ['X-CSRF-Token' => ['wrong-csrf']],
        )->withCookieParams(
            [
                'providentia_refresh' => 'web-refresh',
                'providentia_csrf' => 'web-csrf',
            ],
        );
        $response = new IdentityHandler($this->authentication($store), 'logout')->handle($request);
        self::assertSame(403, $response->getStatusCode());
        self::assertCount(3, $response->getHeader('Set-Cookie'));
    }

    private function authentication(IdentityStore $store): AuthenticationService
    {
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')
            ->willReturnCallback(
                static fn(string $token): string => 'hash:' . $token,
            );
        return new AuthenticationService(
            $store,
            $hasher,
            $this->createStub(UuidGenerator::class),
            new IdentityFixedClock(
                new DateTimeImmutable('2026-08-09T12:00:00+00:00'),
            ),
            $this->createStub(SecureTokenGenerator::class),
            900,
            2592000,
            0,
            0,
            \ProvidentiaTest\Support\AccessFixture::create(),
        );
    }
}
