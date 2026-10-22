<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Application\LoginApplicationKind;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Identity\Http\IdentityHandler;
use Providentia\Identity\Http\SessionResponseFactory;
use Providentia\Identity\Http\WebSessionContext;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Http\HttpProblem;
use ProvidentiaTest\Support\AccessFixture;
use ProvidentiaTest\Unit\SharedKernel\CallbackRequestHandler;
use Psr\Http\Message\ServerRequestInterface;

final class WebSessionIsolationTest extends TestCase
{
    public function testHomeownerAndAdminSignInCookiesCoexistAndAuthenticateTheirOwnDevice(): void
    {
        $cookies = [];
        foreach ([LoginApplicationKind::HOMEOWNER, LoginApplicationKind::ADMIN] as $application) {
            $response = SessionResponseFactory::web($this->tokens($application->value), true, $application);
            foreach ($response->getHeader('Set-Cookie') as $cookie) {
                [$name, $value] = explode('=', explode(';', $cookie)[0], 2);
                $cookies[$name] = rawurldecode($value);
            }
        }
        self::assertCount(6, $cookies);
        $middleware = new BearerAuthenticationMiddleware($this->authentication());
        foreach ([LoginApplicationKind::HOMEOWNER, LoginApplicationKind::ADMIN] as $application) {
            $request = new ServerRequest()->withMethod('POST')
                ->withHeader(WebSessionContext::HEADER, $application->value)
                ->withHeader('X-CSRF-Token', $application->value . '-csrf')
                ->withCookieParams($cookies);
            $response = $middleware->process($request, new CallbackRequestHandler(
                static function (ServerRequestInterface $request): JsonResponse {
                    $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
                    self::assertInstanceOf(AuthenticatedIdentity::class, $identity);

                    return new JsonResponse(['deviceId' => $identity->deviceId]);
                },
            ));
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($application->value . '-device', $body['deviceId']);
        }
        $cleared = SessionResponseFactory::cleared(application: LoginApplicationKind::ADMIN);
        foreach ($cleared->getHeader('Set-Cookie') as $cookie) {
            self::assertStringStartsWith('providentia_admin_', $cookie);
            self::assertStringNotContainsString('homeowner', $cookie);
        }
    }

    public function testScopedAuthenticationNeverFallsBackToLegacyOrAnotherApplicationCookie(): void
    {
        $request = new ServerRequest()->withHeader(WebSessionContext::HEADER, 'homeowner')->withCookieParams([
            'providentia_access' => 'admin-access',
            'providentia_admin_access' => 'admin-access',
        ]);
        $this->expectException(HttpProblem::class);
        new BearerAuthenticationMiddleware($this->authentication())->process($request, $this->handler());
    }

    public function testScopedCsrfCannotBorrowAnotherApplicationsProof(): void
    {
        $request = new ServerRequest()->withMethod('POST')
            ->withHeader(WebSessionContext::HEADER, 'homeowner')
            ->withHeader('X-CSRF-Token', 'admin-csrf')
            ->withCookieParams([
                'providentia_homeowner_access' => 'homeowner-access',
                'providentia_admin_csrf' => 'admin-csrf',
            ]);
        $this->expectException(HttpProblem::class);
        new BearerAuthenticationMiddleware($this->authentication())->process($request, $this->handler());
    }

    public function testLegacyAuthenticationRemainsAvailableWithoutApplicationHeader(): void
    {
        $response = new BearerAuthenticationMiddleware($this->authentication())->process(
            new ServerRequest()->withCookieParams(['providentia_access' => 'homeowner-access']),
            $this->handler(),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith(
            'providentia_access=',
            SessionResponseFactory::web($this->tokens('homeowner'))->getHeader('Set-Cookie')[0],
        );
    }

    public function testScopedRefreshUsesOnlyMatchingCookieAndPreservesNamespace(): void
    {
        $request = new ServerRequest()->withMethod('POST')
            ->withHeader(WebSessionContext::HEADER, 'homeowner')
            ->withHeader('X-CSRF-Token', 'homeowner-csrf')
            ->withCookieParams([
                'providentia_refresh' => 'admin-refresh',
                'providentia_admin_refresh' => 'admin-refresh',
                'providentia_homeowner_refresh' => 'homeowner-refresh',
                'providentia_homeowner_csrf' => 'homeowner-csrf',
            ]);
        $response = new IdentityHandler($this->authentication(), 'refresh')->handle($request);
        self::assertSame(200, $response->getStatusCode());
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            self::assertStringStartsWith('providentia_homeowner_', $cookie);
        }
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('homeowner-device', $body['deviceId']);
    }

    public function testScopedRefreshRejectsMissingCsrfRatherThanRotatingAnotherSession(): void
    {
        $request = new ServerRequest()->withHeader(WebSessionContext::HEADER, 'homeowner')
            ->withCookieParams(['providentia_homeowner_refresh' => 'homeowner-refresh']);
        $this->expectException(HttpProblem::class);
        new IdentityHandler($this->authentication(), 'refresh')->handle($request);
    }

    #[DataProvider('invalidApplicationProvider')]
    public function testApplicationContextUsesAClosedAllowlist(string $value): void
    {
        $this->expectException(HttpProblem::class);
        WebSessionContext::application(new ServerRequest()->withHeader(WebSessionContext::HEADER, $value));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidApplicationProvider(): iterable
    {
        yield 'unknown' => ['support'];
        yield 'combined' => ['homeowner, admin'];
        yield 'case variant' => ['Admin'];
    }

    private function authentication(): AuthenticationService
    {
        $store = $this->createStub(IdentityStore::class);
        $session = static function (string $hash): array {
            $application = str_contains($hash, 'homeowner') ? 'homeowner' : 'admin';

            return [
                'id' => $application . '-session', 'user_id' => 'user', 'device_id' => $application . '-device',
                'active_home_id' => null, 'transport' => 'web', 'refresh_idle_ttl_seconds' => 2592000,
                'refresh_token_hash' => 'hash:' . $application . '-refresh',
                'csrf_token_hash' => 'hash:' . $application . '-csrf',
            ];
        };
        $store->method('findSessionByAccessHash')->willReturnCallback($session);
        $store->method('findSessionByRefreshHash')->willReturnCallback($session);
        $store->method('verifyCsrf')->willReturnCallback(
            static fn (string $id, string $hash): bool => $hash === 'hash:' . str_replace('-session', '-csrf', $id),
        );
        $store->method('rotateSession')->willReturn(true);
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')->willReturnCallback(static fn (string $value): string => 'hash:' . $value);
        $tokens = $this->createStub(SecureTokenGenerator::class);
        $tokens->method('generate')->willReturn('rotated-credential');

        return new AuthenticationService(
            $store,
            $hasher,
            $this->createStub(UuidGenerator::class),
            new IdentityFixedClock(new DateTimeImmutable('2026-10-22T12:00:00Z')),
            $tokens,
            900,
            2592000,
            0,
            0,
            AccessFixture::create(),
        );
    }

    private function handler(): CallbackRequestHandler
    {
        return new CallbackRequestHandler(
            static fn (ServerRequestInterface $_request): JsonResponse => new JsonResponse(['ok' => true]),
        );
    }

    /** @return array<string, mixed> */
    private function tokens(string $application): array
    {
        return [
            'sessionId' => $application . '-session', 'deviceId' => $application . '-device',
            'installationId' => 'installation',
            'userId' => 'user', 'accessToken' => $application . '-access', 'refreshToken' => $application . '-refresh',
            'csrfToken' => $application . '-csrf', 'accessExpiresAt' => '2026-10-22T12:15:00Z',
            'refreshExpiresAt' => null, 'idleExpiresAt' => null, 'refreshIdleTtlSeconds' => null,
        ];
    }
}
