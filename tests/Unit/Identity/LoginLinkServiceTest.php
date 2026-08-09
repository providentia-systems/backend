<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Application\LoginLinkService;
use Providentia\Identity\Application\LoginLinkStore;
use Providentia\Identity\Http\LoginLinkApprovalHandler;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\UuidGenerator;

final class LoginLinkServiceTest extends TestCase
{
    private const REQUEST_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const INSTALLATION_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const HOME_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const SESSION_ID = '01912345-6789-7abc-cdef-0123456789ab';

    public function testStartRetryIsGenericIdempotentAndDoesNotResendEmail(): void
    {
        $saved = null;
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->method('find')->willReturnCallback(
            static function (string $requestId) use (&$saved): ?array {
                return $saved;
            },
        );
        $requests->expects(self::once())->method('create')->willReturnCallback(
            static function (array $request) use (&$saved): void {
                $saved = $request;
            },
        );
        $notifications = $this->createMock(AccountNotificationSender::class);
        $notifications->expects(self::once())->method('sendLoginLink')->with(
            'person@example.test',
            self::REQUEST_ID,
            'approval-token',
        );

        $service = $this->service($requests, notifications: $notifications);
        $first = $service->start($this->startInput());
        $retry = $service->start($this->startInput());

        self::assertSame($first, $retry);
        self::assertSame([
            'accepted',
            'requestId',
            'expiresAt',
            'pollIntervalSeconds',
        ], array_keys($first));
    }

    public function testBrowserLaunchIsScannerSafeAndDoesNotTouchRequestState(): void
    {
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->expects(self::never())->method('find');
        $tokens = $this->createStub(SecureTokenGenerator::class);
        $tokens->method('generate')->willReturn(str_repeat('n', 43));
        $handler = new LoginLinkApprovalHandler(
            $this->service($requests),
            $this->createStub(TemplateRendererInterface::class),
            $tokens,
            'launch',
            true,
            900,
        );
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/login-links/' . self::REQUEST_ID),
            'GET',
        ))->withAttribute('requestId', self::REQUEST_ID);

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            "script-src 'nonce-" . str_repeat('n', 43) . "'",
            $response->getHeaderLine('Content-Security-Policy'),
        );
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testBrowserCaptureMovesPostedCapabilityToCookieAndCleanReviewUrl(): void
    {
        $handler = new LoginLinkApprovalHandler(
            $this->service($this->createStub(LoginLinkStore::class)),
            $this->createStub(TemplateRendererInterface::class),
            $this->createStub(SecureTokenGenerator::class),
            'capture',
            true,
            900,
        );
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://api.example.test/login-links/' . self::REQUEST_ID . '/capture'),
            'POST',
        ))->withAttribute('requestId', self::REQUEST_ID)->withParsedBody([
            'approval' => str_repeat('a', 43),
        ]);

        $response = $handler->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login-links/' . self::REQUEST_ID . '/review', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('approval=', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Max-Age=900', $response->getHeaderLine('Set-Cookie'));
    }

    public function testWrongOriginProofIsRecordedBeforeTheProblemIsRaised(): void
    {
        $row = $this->approvedRequest();
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->method('find')->willReturnCallback(
            static fn (string $requestId): array => $row,
        );
        $requests->expects(self::once())->method('recordFailedProof')->with(
            self::REQUEST_ID,
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn(1);

        try {
            $this->service($requests)->exchange(
                self::REQUEST_ID,
                str_repeat('p', 43),
                str_repeat('wrong-verifier-', 4),
                str_repeat('s', 32),
            );
            self::fail('An invalid origin proof was accepted.');
        } catch (Problem $problem) {
            self::assertSame(401, $problem->status);
            self::assertSame('Login proof rejected', $problem->title);
        }
    }

    public function testSuccessfulExchangeIsSingleUseAndIssuesOneBoundSession(): void
    {
        $verifier = str_repeat('v', 43);
        $state = str_repeat('s', 32);
        $row = $this->approvedRequest() + [
            'user_id' => self::USER_ID,
            'installation_id' => self::INSTALLATION_ID,
            'device_name' => 'Kitchen tablet',
            'platform' => 'android',
            'transport' => 'native',
            'refresh_idle_ttl_seconds' => 5184000,
        ];
        $row['code_challenge'] = $this->s256($verifier);
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->method('find')->willReturnCallback(
            static function (string $requestId) use (&$row): array {
                return $row;
            },
        );
        $requests->expects(self::once())->method('reserveExchange')->willReturnCallback(
            static function () use (&$row): bool {
                $row['status'] = 'exchanging';

                return true;
            },
        );
        $requests->expects(self::once())->method('completeExchange')->willReturnCallback(
            static function () use (&$row): void {
                $row['status'] = 'exchanged';
            },
        );
        $identities = $this->createMock(IdentityStore::class);
        $identities->method('findUserById')->willReturn([
            'id' => self::USER_ID,
            'status' => 'active',
            'email_verified_at' => '2026-08-09 11:00:00',
        ]);
        $identities->expects(self::once())->method('createSession');
        $homes = $this->createStub(HomeStore::class);
        $homes->method('listForUser')->willReturn([['id' => self::HOME_ID]]);
        $service = $this->service(
            $requests,
            $identities,
            $homes,
            $this->ids(self::SESSION_ID),
        );

        $session = $service->exchange(
            self::REQUEST_ID,
            str_repeat('p', 43),
            $verifier,
            $state,
        );
        self::assertSame(self::SESSION_ID, $session['sessionId']);
        self::assertSame('native', $session['transport']);

        try {
            $service->exchange(self::REQUEST_ID, str_repeat('p', 43), $verifier, $state);
            self::fail('A login-link exchange was replayed.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
        }
    }

    public function testDenyAtExpiryPersistsExpiredTerminalState(): void
    {
        $row = [
            'id' => self::REQUEST_ID,
            'status' => 'pending',
            'approval_token_hash' => 'hash:approval-token',
            'expires_at' => '2026-08-09 12:00:00',
            'created_at' => '2026-08-09 11:45:00',
        ];
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
        $requests->expects(self::never())->method('deny');

        try {
            $this->service($requests)->deny(self::REQUEST_ID, 'approval-token');
            self::fail('An expired login request was denied as though still pending.');
        } catch (Problem $problem) {
            self::assertSame(410, $problem->status);
            self::assertSame('expired', $row['status']);
        }
    }

    public function testExpiredApprovedStatusReportsTheExchangeDeadline(): void
    {
        $row = $this->approvedRequest();
        $row['exchange_expires_at'] = '2026-08-09 12:00:00';
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

        $status = $this->service($requests)->status(self::REQUEST_ID, str_repeat('p', 43));

        self::assertSame('expired', $status['status']);
        self::assertSame('2026-08-09T12:00:00+00:00', $status['expiresAt']);
    }

    public function testFirstVerifiedAccountReceivesExactlyOneOwnedDefaultHome(): void
    {
        $requests = $this->approvalStore();
        $identities = $this->createMock(IdentityStore::class);
        $identities->method('findUserByEmail')->willReturn(null);
        $identities->expects(self::once())->method('createUser')->with(
            self::USER_ID,
            'person@example.test',
            'password-hash',
            'person',
            'en-NA',
            'Africa/Windhoek',
            self::isInstanceOf(DateTimeImmutable::class),
        );
        $identities->expects(self::once())->method('markEmailVerified')->with(
            self::USER_ID,
            self::isInstanceOf(DateTimeImmutable::class),
        );
        $homes = $this->createMock(HomeStore::class);
        $homes->method('listForUser')->with(self::USER_ID)->willReturn([]);
        $homes->expects(self::once())->method('createHome')->with(
            self::HOME_ID,
            self::USER_ID,
            'My home',
            'en-NA',
            'NAD',
            'Africa/Windhoek',
            self::isInstanceOf(DateTimeImmutable::class),
        );
        $homes->expects(self::once())->method('recordAudit');

        $result = $this->service(
            $requests,
            $identities,
            $homes,
            $this->ids(self::USER_ID, self::HOME_ID, 'audit-id', 'admin-audit-id'),
        )->approve(self::REQUEST_ID, 'approval-token');

        self::assertSame(['status' => 'approved'], $result);
    }

    public function testExistingVerifiedAccountNeverReceivesAnOnboardingHome(): void
    {
        $requests = $this->approvalStore();
        $identities = $this->createStub(IdentityStore::class);
        $identities->method('findUserByEmail')->willReturn([
            'id' => self::USER_ID,
            'status' => 'active',
            'email_verified_at' => '2026-08-01 10:00:00',
        ]);
        $identities->method('claimEmailVerification')->willReturn(false);
        $homes = $this->createMock(HomeStore::class);
        $homes->expects(self::never())->method('createHome');

        $result = $this->service(
            $requests,
            $identities,
            $homes,
            $this->ids('admin-audit-id'),
        )->approve(self::REQUEST_ID, 'approval-token');

        self::assertSame(['status' => 'approved'], $result);
    }

    /** @return array<string, mixed> */
    private function startInput(): array
    {
        return [
            'requestId' => self::REQUEST_ID,
            'email' => 'person@example.test',
            'pollChallenge' => str_repeat('p', 43),
            'codeChallenge' => str_repeat('c', 43),
            'codeChallengeMethod' => 'S256',
            'state' => str_repeat('s', 32),
            'installationId' => self::INSTALLATION_ID,
            'deviceName' => 'Kitchen tablet',
            'platform' => 'android',
            'transport' => 'native',
        ];
    }

    /** @return array<string, mixed> */
    private function approvedRequest(): array
    {
        return [
            'id' => self::REQUEST_ID,
            'status' => 'approved',
            'poll_challenge' => $this->s256(str_repeat('p', 43)),
            'state_hash' => 'hash:' . str_repeat('s', 32),
            'code_challenge' => str_repeat('c', 43),
            'expires_at' => '2026-08-09 12:15:00',
            'exchange_expires_at' => '2026-08-09 12:02:00',
            'approved_at' => '2026-08-09 12:00:00',
        ];
    }

    private function approvalStore(): LoginLinkStore
    {
        $row = [
            'id' => self::REQUEST_ID,
            'status' => 'pending',
            'normalized_email' => 'person@example.test',
            'approval_token_hash' => 'hash:approval-token',
            'device_name' => 'Kitchen tablet',
            'platform' => 'android',
            'expires_at' => '2026-08-09 12:15:00',
            'created_at' => '2026-08-09 12:00:00',
        ];
        $requests = $this->createMock(LoginLinkStore::class);
        $requests->method('find')->willReturn($row);
        $requests->expects(self::once())->method('lockEmail')->with('person@example.test');
        $requests->method('reserveApproval')->willReturn(true);
        $requests->expects(self::once())->method('completeApproval');

        return $requests;
    }

    private function service(
        LoginLinkStore $requests,
        ?IdentityStore $identities = null,
        ?HomeStore $homes = null,
        ?UuidGenerator $ids = null,
        ?AccountNotificationSender $notifications = null,
    ): LoginLinkService {
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')->willReturnCallback(
            static fn (string $token): string => 'hash:' . $token,
        );
        $hasher->method('hashPassword')->willReturn('password-hash');
        $tokens = $this->createStub(SecureTokenGenerator::class);
        $tokens->method('generate')->willReturn('approval-token');
        $identityStore = $identities ?? $this->createStub(IdentityStore::class);
        $notificationSender = $notifications ?? $this->createStub(AccountNotificationSender::class);
        $uuidGenerator = $ids ?? $this->createStub(UuidGenerator::class);
        $transactions = new IdentityTransactionManager();
        $authentication = new AuthenticationService(
            $identityStore,
            $hasher,
            $notificationSender,
            $uuidGenerator,
            new IdentityFixedClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00')),
            $transactions,
            $tokens,
            900,
            2592000,
        );

        return new LoginLinkService(
            $requests,
            $identityStore,
            $homes ?? $this->createStub(HomeStore::class),
            $hasher,
            $notificationSender,
            $authentication,
            $uuidGenerator,
            new IdentityFixedClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00')),
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

    private function ids(string ...$values): UuidGenerator
    {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturnOnConsecutiveCalls(...$values);

        return $ids;
    }

    private function s256(string $value): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $value, true)), '+/', '-_'), '=');
    }
}
