<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Application\LoginLinkService;
use Providentia\Identity\Application\LoginLinkStore;
use Providentia\Identity\Http\LoginLinkApprovalHandler;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Http\Message\ServerRequestInterface;

final class LoginLinkApprovalHandlerTest extends TestCase
{
    private const ORIGIN = 'https://api.example.test';
    private const REQUEST_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-adef-0123456789ab';

    public function testLaunchScrubsTheFragmentWithoutReadingRequestState(): void
    {
        $secret = str_repeat('a', 43);
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->expects(self::never())->method('find');
        $handler = $this->handler($requests, 'launch');
        $request = $this->request('GET', 'admin', fragment: 'approval=' . $secret);

        $response = $handler->handle($request);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('window.location.hash.slice(1)', $body);
        self::assertStringContainsString('window.history.replaceState', $body);
        self::assertStringContainsString('/login-links/admin/' . self::REQUEST_ID . '/capture', $body);
        self::assertStringNotContainsString($secret, $body);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertStringContainsString("script-src 'nonce-", $response->getHeaderLine(
            'Content-Security-Policy',
        ));
        self::assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine(
            'Content-Security-Policy',
        ));
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function testCaptureMovesOnlyAWellShapedCapabilityToARequestScopedCookie(): void
    {
        $approvalToken = str_repeat('b', 43);
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->expects(self::never())->method('find');
        $secureResponse = $this->handler($requests, 'capture')->handle($this->formRequest(
            'capture',
            'homeowner',
            ['approval' => $approvalToken],
        ));

        self::assertSame(303, $secureResponse->getStatusCode());
        self::assertSame(
            '/login-links/homeowner/' . self::REQUEST_ID . '/review',
            $secureResponse->getHeaderLine('Location'),
        );
        self::assertStringNotContainsString($approvalToken, $secureResponse->getHeaderLine('Location'));
        self::assertStringNotContainsString('?', $secureResponse->getHeaderLine('Location'));
        self::assertStringNotContainsString('#', $secureResponse->getHeaderLine('Location'));
        $secureCookie = $secureResponse->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('providentia_login_link_approval=' . $approvalToken, $secureCookie);
        self::assertStringContainsString(
            'Path=/login-links/homeowner/' . self::REQUEST_ID,
            $secureCookie,
        );
        self::assertStringContainsString('; Secure', $secureCookie);
        self::assertStringContainsString('; HttpOnly', $secureCookie);
        self::assertStringContainsString('; SameSite=Strict', $secureCookie);
        self::assertStringContainsString('; Max-Age=900', $secureCookie);

        $developmentResponse = $this->handler($requests, 'capture', false)->handle($this->formRequest(
            'capture',
            'homeowner',
            ['approval' => $approvalToken],
        ));
        self::assertStringNotContainsString('; Secure', $developmentResponse->getHeaderLine('Set-Cookie'));

        $invalidResponse = $this->handler($requests, 'capture')->handle($this->formRequest(
            'capture',
            'homeowner',
            ['approval' => 'too-short'],
        ));
        self::assertStringContainsString(
            'providentia_login_link_approval=deleted',
            $invalidResponse->getHeaderLine('Set-Cookie'),
        );
        self::assertStringContainsString('Max-Age=0', $invalidResponse->getHeaderLine('Set-Cookie'));
    }

    public function testCaptureRejectsAnotherCorsAllowedOrigin(): void
    {
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->expects(self::never())->method('find');
        $request = $this->formRequest('capture', 'admin', ['approval' => str_repeat('c', 43)])
            ->withHeader('Origin', 'https://client.example.test');

        $response = $this->handler($requests, 'capture')->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Login link unavailable', (string) $response->getBody());
        self::assertStringContainsString('Max-Age=0', implode('; ', $response->getHeader('Set-Cookie')));
    }

    public function testReviewIsApplicationBoundAndEscapesRequestMetadata(): void
    {
        $row = $this->approvalRequest('admin');
        $row['device_name'] = '<script>alert(1)</script>';
        $row['platform'] = '"><img src=x onerror=alert(1)>';
        $requests = $this->createStub(LoginLinkStore::class);
        $requests->method('find')->willReturn($row);
        $request = $this->request('GET', 'admin', 'review')
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withCookieParams(['providentia_login_link_approval' => 'approval-token']);

        $response = $this->handler($requests, 'review')->handle($request);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Approve only if you started this login from Providentia Admin.', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringNotContainsString('person@example.test', $body);
        self::assertStringContainsString('name="csrf" value="browser-token"', $body);
        self::assertStringContainsString(
            'providentia_login_link_csrf=browser-token',
            $response->getHeaderLine('Set-Cookie'),
        );
    }

    public function testWrongApplicationReviewIsUnavailableAndClearsCookies(): void
    {
        $requests = $this->createStub(LoginLinkStore::class);
        $requests->method('find')->willReturn($this->approvalRequest('homeowner'));
        $request = $this->request('GET', 'admin', 'review')
            ->withCookieParams(['providentia_login_link_approval' => 'approval-token']);

        $response = $this->handler($requests, 'review')->handle($request);
        $cookies = implode('; ', $response->getHeader('Set-Cookie'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('providentia_login_link_approval=deleted', $cookies);
        self::assertStringContainsString('providentia_login_link_csrf=deleted', $cookies);
        self::assertStringContainsString('Max-Age=0', $cookies);
    }

    public function testExpiredReviewPersistsExpiryAndClearsCookies(): void
    {
        $row = $this->approvalRequest('homeowner');
        $row['expires_at'] = '2026-08-09 12:00:00';
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->method('find')->willReturnCallback(
            static function (string $requestId) use (&$row): array {
                return $row;
            },
        );
        $requests->expects(self::once())->method('expire')->willReturnCallback(
            static function () use (&$row): void {
                $row['status'] = 'expired';
            },
        );
        $request = $this->request('GET', 'homeowner', 'review')
            ->withCookieParams(['providentia_login_link_approval' => 'approval-token']);

        $response = $this->handler($requests, 'review')->handle($request);

        self::assertSame(410, $response->getStatusCode());
        self::assertStringContainsString('Login link expired', (string) $response->getBody());
        self::assertCount(2, $response->getHeader('Set-Cookie'));
    }

    public function testDecisionRequiresExactDoubleSubmitCsrfProof(): void
    {
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->expects(self::never())->method('find');
        $request = $this->formRequest('approve', 'admin', ['csrf' => 'wrong-token'])
            ->withCookieParams([
                'providentia_login_link_approval' => 'approval-token',
                'providentia_login_link_csrf' => 'browser-token',
            ]);

        $response = $this->handler($requests, 'approve')->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Login link unavailable', (string) $response->getBody());
        self::assertCount(2, $response->getHeader('Set-Cookie'));
    }

    public function testApprovalClearsCeremonyCookiesWithoutCreatingABrowserSession(): void
    {
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->method('find')->willReturn($this->approvalRequest('admin'));
        $requests->expects(self::once())->method('lockEmail')->with('person@example.test');
        $requests->expects(self::once())->method('reserveApproval')->willReturn(true);
        $requests->expects(self::once())->method('completeApproval')->with(
            self::REQUEST_ID,
            self::USER_ID,
            null,
            self::isInstanceOf(DateTimeImmutable::class),
        );
        $identities = $this->createMock(IdentityStore::class);
        $identities->method('findUserByEmail')->willReturn([
            'id' => self::USER_ID,
            'status' => 'active',
        ]);
        $identities->method('claimEmailVerification')->willReturn(false);
        $identities->expects(self::once())->method('activatePendingAdministratorGrant')->willReturn(false);
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('audit-id');
        $request = $this->formRequest('approve', 'admin', ['csrf' => 'browser-token'])
            ->withCookieParams([
                'providentia_login_link_approval' => 'approval-token',
                'providentia_login_link_csrf' => 'browser-token',
            ]);

        $response = $this->handler(
            $requests,
            'approve',
            identities: $identities,
            ids: $ids,
        )->handle($request);
        $cookies = implode('; ', $response->getHeader('Set-Cookie'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Login approved', (string) $response->getBody());
        self::assertStringContainsString('providentia_login_link_approval=deleted', $cookies);
        self::assertStringContainsString('providentia_login_link_csrf=deleted', $cookies);
        self::assertStringContainsString('Max-Age=0', $cookies);
        self::assertStringNotContainsString('access', mb_strtolower($cookies));
        self::assertStringNotContainsString('refresh', mb_strtolower($cookies));
        self::assertStringNotContainsString('session', mb_strtolower($cookies));
        self::assertSame('', $response->getHeaderLine('Location'));
    }

    public function testDenialIsExplicitAndClearsCeremonyCookies(): void
    {
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->method('find')->willReturn($this->approvalRequest('homeowner'));
        $requests->expects(self::once())->method('deny')->willReturn(true);
        $request = $this->formRequest('deny', 'homeowner', ['csrf' => 'browser-token'])
            ->withCookieParams([
                'providentia_login_link_approval' => 'approval-token',
                'providentia_login_link_csrf' => 'browser-token',
            ]);

        $response = $this->handler($requests, 'deny')->handle($request);
        $cookies = implode('; ', $response->getHeader('Set-Cookie'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Login denied', (string) $response->getBody());
        self::assertStringContainsString('providentia_login_link_approval=deleted', $cookies);
        self::assertStringContainsString('providentia_login_link_csrf=deleted', $cookies);
    }

    /** @param array<string, mixed> $body */
    private function formRequest(string $action, string $application, array $body): ServerRequestInterface
    {
        return $this->request('POST', $application, $action)
            ->withHeader('Origin', self::ORIGIN)
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($body);
    }

    private function request(
        string $method,
        string $application,
        string $action = '',
        string $fragment = '',
    ): ServerRequestInterface {
        $path = '/login-links/' . $application . '/' . self::REQUEST_ID
            . ($action === '' ? '' : '/' . $action);
        $uri = self::ORIGIN . $path . ($fragment === '' ? '' : '#' . $fragment);

        return (new ServerRequest([], [], new Uri($uri), $method))
            ->withAttribute('applicationKind', $application)
            ->withAttribute('requestId', self::REQUEST_ID);
    }

    private function handler(
        LoginLinkStore $requests,
        string $action,
        bool $cookieSecure = true,
        ?IdentityStore $identities = null,
        ?UuidGenerator $ids = null,
    ): LoginLinkApprovalHandler {
        $tokens = $this->createStub(SecureTokenGenerator::class);
        $tokens->method('generate')->willReturn('browser-token');

        return new LoginLinkApprovalHandler(
            $this->service($requests, $identities, $ids),
            $tokens,
            $action,
            self::ORIGIN,
            $cookieSecure,
            900,
        );
    }

    /** @return array<string, mixed> */
    private function approvalRequest(string $application): array
    {
        return [
            'id' => self::REQUEST_ID,
            'status' => 'pending',
            'application_kind' => $application,
            'normalized_email' => 'person@example.test',
            'approval_token_hash' => 'hash:approval-token',
            'device_name' => 'Office workstation',
            'platform' => 'linux',
            'expires_at' => '2026-08-09 12:15:00',
            'created_at' => '2026-08-09 12:00:00',
        ];
    }

    private function service(
        LoginLinkStore $requests,
        ?IdentityStore $identities,
        ?UuidGenerator $ids,
    ): LoginLinkService {
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')->willReturnCallback(
            static fn (string $token): string => 'hash:' . $token,
        );
        $notifications = $this->createStub(AccountNotificationSender::class);
        $tokens = $this->createStub(SecureTokenGenerator::class);
        $tokens->method('generate')->willReturn('approval-token');
        $identityStore = $identities ?? $this->createStub(IdentityStore::class);
        $uuidGenerator = $ids ?? $this->createStub(UuidGenerator::class);
        $transactions = new IdentityTransactionManager();
        $clock = new IdentityFixedClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00'));
        $authentication = new AuthenticationService(
            $identityStore,
            $hasher,
            $notifications,
            $uuidGenerator,
            $clock,
            $transactions,
            $tokens,
            900,
            2592000,
        );

        return new LoginLinkService(
            $requests,
            $identityStore,
            $this->createStub(HomeStore::class),
            $hasher,
            $notifications,
            $authentication,
            $uuidGenerator,
            $clock,
            $transactions,
            $tokens,
            900,
            120,
            3,
            2592000,
            5184000,
            [],
            [
                'name' => 'My home',
                'locale' => 'en-NA',
                'currency' => 'NAD',
                'timezone' => 'Africa/Windhoek',
            ],
        );
    }
}
