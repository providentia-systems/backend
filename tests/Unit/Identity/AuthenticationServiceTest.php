<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Application\LoginApplicationKind;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\UuidGenerator;

final class AuthenticationServiceTest extends TestCase
{
    private const USER_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const SESSION_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const DEVICE_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const INSTALLATION_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const ACCOUNT_SCOPED_DEVICE_ID = 'fccd73a3-5252-8327-8069-735fdf4674cc';
    public function testLoginLinkSessionIssuanceCreatesBoundedDeviceSession(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::once())
            ->method('createSession')
            ->with(
                self::SESSION_ID,
                self::USER_ID,
                self::ACCOUNT_SCOPED_DEVICE_ID,
                'Kitchen tablet',
                'android',
                'hash:access-token',
                'hash:refresh-token',
                'hash:csrf-token',
                self::callback(
                    static fn(DateTimeImmutable $date): bool => $date->format(DATE_ATOM) === '2026-07-30T12:15:00+00:00',
                ),
                self::callback(
                    static fn(DateTimeImmutable $date): bool => $date->format(DATE_ATOM) === '2026-09-28T12:00:00+00:00',
                ),
                self::isInstanceOf(DateTimeImmutable::class),
                'native',
                5184000,
                self::INSTALLATION_ID,
                null,
            );
        $hasher = $this->hasher();
        $tokens = $this->tokenGenerator('access-token', 'refresh-token', 'csrf-token');
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')
            ->willReturn(self::SESSION_ID);
        $result = $this->service($store, $hasher, $tokens, $ids)
            ->issueVerifiedSession(
                self::USER_ID,
                self::INSTALLATION_ID,
                'Kitchen tablet',
                'android',
                'native',
                5184000,
                null,
            );
        self::assertSame('access-token', $result['accessToken']);
        self::assertSame('refresh-token', $result['refreshToken']);
        self::assertSame('csrf-token', $result['csrfToken']);
        self::assertSame(
            self::ACCOUNT_SCOPED_DEVICE_ID,
            $result['deviceId'],
        );
        self::assertSame(self::INSTALLATION_ID, $result['installationId']);
        self::assertNotSame($result['deviceId'], $result['installationId']);
        self::assertSame(self::USER_ID, $result['userId']);
        self::assertSame(5184000, $result['refreshIdleTtlSeconds']);
        self::assertSame('native', $result['transport']);
    }

    public function testWebSessionIdleTimeIsClampedToTheWebTransportMaximum(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::once())
            ->method('createSession');
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')
            ->willReturn(self::SESSION_ID);
        $result = $this->service(
            $store,
            $this->hasher(),
            null,
            $ids,
            webIdleTtlSeconds: 2592000,
        )
            ->issueVerifiedSession(
                self::USER_ID,
                self::INSTALLATION_ID,
                'Web browser',
                'web',
                'web',
                5184000,
                null,
            );
        self::assertSame('web', $result['transport']);
        self::assertSame(2592000, $result['refreshIdleTtlSeconds']);
    }

    public function testDurableSessionHasNoIdleExpiryUntilExplicitRevocation(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::once())
            ->method('createSession')
            ->with(
                self::anything(),
                self::USER_ID,
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::isInstanceOf(DateTimeImmutable::class),
                self::isNull(),
                self::isInstanceOf(DateTimeImmutable::class),
                'native',
                0,
                self::INSTALLATION_ID,
                null,
            );
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')
            ->willReturn(self::SESSION_ID);
        $result = $this->service($store, $this->hasher(), null, $ids)
            ->issueVerifiedSession(
                self::USER_ID,
                self::INSTALLATION_ID,
                'Kitchen tablet',
                'android',
                'native',
                0,
                null,
            );
        self::assertNull($result['idleExpiresAt']);
        self::assertNull($result['refreshExpiresAt']);
        self::assertNull($result['refreshIdleTtlSeconds']);
        self::assertNotNull($result['accessExpiresAt']);
    }

    public function testRefreshKeepsADurableSessionUnlimited(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->method('findSessionByRefreshHash')
            ->willReturn(
                [
                'id' => self::SESSION_ID,
                'user_id' => self::USER_ID,
                'device_id' => self::ACCOUNT_SCOPED_DEVICE_ID,
                'installation_id' => self::INSTALLATION_ID,
                'refresh_token_hash' => 'hash:old-refresh',
                'transport' => 'native',
                'refresh_idle_ttl_seconds' => 0,
                'active_home_id' => null,
                ],
            );
        $store->expects(self::once())
            ->method('rotateSession')
            ->with(
                self::SESSION_ID,
                'hash:old-refresh',
                self::anything(),
                self::anything(),
                self::anything(),
                self::isInstanceOf(DateTimeImmutable::class),
                self::isNull(),
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                true,
            );
        $result = $this->service(
            $store,
            $this->hasher(),
            $this->tokenGenerator('next-access', 'next-refresh', 'next-csrf'),
        )
            ->refresh(
                'old-refresh',
            );
        self::assertNull($result['idleExpiresAt']);
        self::assertNull($result['refreshExpiresAt']);
        self::assertNull($result['refreshIdleTtlSeconds']);
    }

    public function testRefreshClampsALegacyUnlimitedSessionToAFiniteCeiling(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('findSessionByRefreshHash')
            ->willReturn(
                [
                'id' => self::SESSION_ID,
                'user_id' => self::USER_ID,
                'device_id' => self::ACCOUNT_SCOPED_DEVICE_ID,
                'installation_id' => self::INSTALLATION_ID,
                'refresh_token_hash' => 'hash:old-refresh',
                'transport' => 'native',
                'refresh_idle_ttl_seconds' => 0,
                'active_home_id' => null,
                ],
            );
        $store->method('rotateSession')
            ->willReturn(true);
        $result = $this->service(
            $store,
            $this->hasher(),
            $this->tokenGenerator('next-access', 'next-refresh', 'next-csrf'),
            nativeIdleTtlSeconds: 5184000,
        )
            ->refresh(
                'old-refresh',
            );
        self::assertSame(5184000, $result['refreshIdleTtlSeconds']);
        self::assertSame(
            '2026-09-28T12:00:00+00:00',
            $result['idleExpiresAt'],
        );
    }

    public function testSessionIssuanceRejectsAnUnknownTransport(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::never())
            ->method('createSession');
        try {
            $this->service($store, $this->hasher())
                ->issueVerifiedSession(
                    self::USER_ID,
                    self::INSTALLATION_ID,
                    'Kitchen tablet',
                    'android',
                    'carrier-pigeon',
                    5184000,
                    null,
                );
            self::fail('An unknown session transport was accepted.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
        }
    }

    public function testSessionIssuanceRejectsAnOutOfRangeIdleTime(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::never())
            ->method('createSession');
        try {
            $this->service($store, $this->hasher())
                ->issueVerifiedSession(
                    self::USER_ID,
                    self::INSTALLATION_ID,
                    'Kitchen tablet',
                    'android',
                    'native',
                    899,
                    null,
                );
            self::fail(
                'An out-of-range session idle time was accepted.',
            );
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
        }
    }

    public function testRefreshReplayRevokesCompromisedSession(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->method('findSessionByRefreshHash')
            ->willReturn(null);
        $store->expects(self::once())
            ->method('revokeRefreshReplay')
            ->with(
                'hash:old-refresh',
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                true,
            );
        try {
            $this->service($store, $this->hasher())
                ->refresh('old-refresh');
            self::fail('A replayed refresh credential was accepted.');
        } catch (Problem $problem) {
            self::assertSame('Credential replay detected', $problem->title);
        }
    }

    public function testUnknownRefreshCredentialFailsWithoutRevealingReplayState(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('findSessionByRefreshHash')
            ->willReturn(null);
        $store->method('revokeRefreshReplay')
            ->willReturn(false);
        try {
            $this->service($store, $this->hasher())
                ->refresh('unknown-refresh');
            self::fail('An unknown refresh credential was accepted.');
        } catch (Problem $problem) {
            self::assertSame(401, $problem->status);
            self::assertSame('Authentication failed', $problem->title);
        }
    }

    public function testRefreshRotatesEverySessionCredential(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->method('findSessionByRefreshHash')
            ->willReturn(
                [
                'id' => self::SESSION_ID,
                'user_id' => self::USER_ID,
                'device_id' => self::DEVICE_ID,
                'installation_id' => '01912345-6789-7abc-bdef-0123456789ab',
                'refresh_token_hash' => 'stored-refresh-hash',
                ],
            );
        $store->expects(self::once())
            ->method('rotateSession')
            ->with(
                self::SESSION_ID,
                'stored-refresh-hash',
                'hash:next-access',
                'hash:next-refresh',
                'hash:next-csrf',
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                true,
            );
        $tokens = $this->tokenGenerator('next-access', 'next-refresh', 'next-csrf');
        $result = $this->service($store, $this->hasher(), $tokens)
            ->refresh('current-refresh');
        self::assertSame(self::USER_ID, $result['userId']);
        self::assertSame('next-access', $result['accessToken']);
        self::assertSame('next-refresh', $result['refreshToken']);
        self::assertSame('next-csrf', $result['csrfToken']);
        self::assertSame('native', $result['transport']);
        self::assertSame(2592000, $result['refreshIdleTtlSeconds']);
        self::assertSame(
            '01912345-6789-7abc-bdef-0123456789ab',
            $result['installationId'],
        );
    }

    public function testConcurrentRefreshRotationIsReportedAsReplay(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('findSessionByRefreshHash')
            ->willReturn(
                [
                'id' => self::SESSION_ID,
                'user_id' => self::USER_ID,
                'device_id' => self::DEVICE_ID,
                'refresh_token_hash' => 'stored-refresh-hash',
                ],
            );
        $store->method('rotateSession')
            ->willReturn(false);
        try {
            $this->service($store, $this->hasher())
                ->refresh('current-refresh');
            self::fail(
                'A concurrently rotated refresh credential was accepted.',
            );
        } catch (Problem $problem) {
            self::assertSame(401, $problem->status);
            self::assertSame('Credential replay detected', $problem->title);
        }
    }

    public function testAuthenticateBuildsIdentityFromSessionAndRoles(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->method('findSessionByAccessHash')
            ->willReturn(
                [
                'user_id' => self::USER_ID,
                'id' => self::SESSION_ID,
                'device_id' => self::DEVICE_ID,
                'active_home_id' => null,
                ],
            );
        $store->method('platformRoles')
            ->with(self::USER_ID)
            ->willReturn(
                ['billing_operator'],
            );
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')
            ->willReturn('access-hash');
        $identity = $this->service($store, $hasher)
            ->authenticate('access-token');
        self::assertSame(self::USER_ID, $identity->userId);
        self::assertSame(self::SESSION_ID, $identity->sessionId);
        self::assertSame(self::DEVICE_ID, $identity->deviceId);
        self::assertSame([], $identity->platformRoles);
        self::assertContains(
            'billing.read',
            $identity->administratorPermissions,
        );
    }

    public function testListSessionsMarksOnlyTheCallingDeviceSessionAsCurrent(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('listSessions')
            ->willReturn(
                [
                ['id' => self::SESSION_ID],
                ['id' => '01912345-6789-7abc-bdef-0123456789ab'],
                ],
            );
        $sessions = $this->service($store, $this->hasher())
            ->listSessions($this->identity());
        self::assertTrue($sessions[0]['current']);
        self::assertFalse($sessions[1]['current']);
    }

    public function testStepUpProofCannotBeConsumedByAnotherAccount(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::once())
            ->method('consumeOneTimeToken')
            ->with(
                'step-up-ownership:homeowner',
                'hash:' . self::SESSION_ID . ':step-up-token',
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                '01912345-6789-7abc-bdef-0123456789ab',
            );
        try {
            $this->service($store, $this->hasher())
                ->consumeStepUp(
                    $this->identity(),
                    'step-up-token',
                    'ownership-transfer',
                );
            self::fail('A step-up proof crossed between accounts.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
            self::assertSame('Invalid step-up proof', $problem->title);
        }
    }

    public function testRevokingAnUnknownSessionIsReportedAsNotFound(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('revokeSession')
            ->willReturn(false);
        try {
            $this->service($store, $this->hasher())
                ->revokeSession(
                    $this->identity(),
                    '01912345-6789-7abc-bdef-0123456789ab',
                );
            self::fail(
                'An unknown session revocation was reported as successful.',
            );
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
        }
    }

    public function testEmptyLogoutProofsNeverReachTheSessionStore(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::never())
            ->method('revokeSessionByRefreshProof');
        $store->expects(self::never())
            ->method('revokeSessionByRefreshHash');
        $service = $this->service($store, $this->hasher());
        self::assertFalse(
            $service->revokeSessionByRefreshProof('', 'csrf'),
        );
        self::assertFalse(
            $service->revokeSessionByRefreshProof('refresh', ''),
        );
        self::assertFalse($service->revokeSessionByRefreshToken(''));
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER_ID,
            self::SESSION_ID,
            self::DEVICE_ID,
            null,
            [],
            \ProvidentiaTest\Support\AccessFixture::administratorPermissions([]),
        );
    }

    private function hasher(): CredentialHasher
    {
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')
            ->willReturnCallback(
                static fn(string $token): string => 'hash:' . $token,
            );
        return $hasher;
    }

    private function service(
        IdentityStore $store,
        CredentialHasher $hasher,
        ?SecureTokenGenerator $tokens = null,
        ?UuidGenerator $ids = null,
        ?IdentityTransactionManager $transactions = null,
        ?AccountNotificationSender $notifications = null,
        int $webIdleTtlSeconds = 0,
        int $nativeIdleTtlSeconds = 0,
    ): AuthenticationService {
        return new AuthenticationService(
            $store,
            $hasher,
            $ids ?? $this->createStub(UuidGenerator::class),
            new IdentityFixedClock(
                new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
            ),
            $tokens ?? $this->createStub(SecureTokenGenerator::class),
            900,
            2592000,
            $webIdleTtlSeconds,
            $nativeIdleTtlSeconds,
            \ProvidentiaTest\Support\AccessFixture::create(),
        );
    }

    private function tokenGenerator(string ...$tokens): SecureTokenGenerator
    {
        $generator = $this->createStub(SecureTokenGenerator::class);
        $generator->method('generate')
            ->willReturnOnConsecutiveCalls(...array_values($tokens));
        return $generator;
    }
}
