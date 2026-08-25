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
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\UuidGenerator;

final class AuthenticationServiceTest extends TestCase
{
    private const USER_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const SESSION_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const DEVICE_ID = '01912345-6789-7abc-adef-0123456789ab';

    public function testSuccessfulLoginCreatesBoundedDeviceSession(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->method('findUserByEmail')->with('user@example.test')->willReturn([
            'id' => self::USER_ID,
            'password_hash' => 'stored-password-hash',
            'locked_until' => null,
            'email_verified_at' => '2026-07-30 10:00:00',
            'status' => 'active',
        ]);
        $store->expects(self::once())->method('clearFailedLogin')->with(self::USER_ID);
        $store->expects(self::once())
            ->method('createSession')
            ->with(
                self::SESSION_ID,
                self::USER_ID,
                self::DEVICE_ID,
                'Kitchen tablet',
                'android',
                'hash:access-token',
                'hash:refresh-token',
                'hash:csrf-token',
                self::callback(
                    static fn (DateTimeImmutable $date): bool => $date->format(DATE_ATOM)
                        === '2026-07-30T12:15:00+00:00',
                ),
                self::callback(
                    static fn (DateTimeImmutable $date): bool => $date->format(DATE_ATOM)
                        === '2026-09-28T12:00:00+00:00',
                ),
                self::isInstanceOf(DateTimeImmutable::class),
                'native',
                5184000,
                self::DEVICE_ID,
                null,
            );
        $hasher = $this->createMock(CredentialHasher::class);
        $hasher->method('verifyPassword')
            ->with('Valid-password-123', 'stored-password-hash')
            ->willReturn(true);
        $hasher->method('hashToken')
            ->willReturnCallback(static fn (string $token): string => 'hash:' . $token);
        $tokens = $this->tokenGenerator('access-token', 'refresh-token', 'csrf-token');
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn(self::SESSION_ID);

        $result = $this->service($store, $hasher, $tokens, $ids)->login(
            '  USER@Example.Test ',
            'Valid-password-123',
            strtoupper(self::DEVICE_ID),
            ' Kitchen tablet ',
            ' android ',
        );

        self::assertSame('access-token', $result['accessToken']);
        self::assertSame('refresh-token', $result['refreshToken']);
        self::assertSame('csrf-token', $result['csrfToken']);
        self::assertSame(self::DEVICE_ID, $result['deviceId']);
        self::assertSame(self::DEVICE_ID, $result['installationId']);
        self::assertSame(5184000, $result['refreshIdleTtlSeconds']);
    }

    public function testInvalidPasswordRecordsFailureWithoutCreatingSession(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->method('findUserByEmail')->willReturn([
            'id' => self::USER_ID,
            'password_hash' => 'stored-password-hash',
            'locked_until' => null,
            'email_verified_at' => '2026-07-30 10:00:00',
            'status' => 'active',
        ]);
        $store->expects(self::once())
            ->method('recordFailedLogin')
            ->with(self::USER_ID, self::isInstanceOf(DateTimeImmutable::class));
        $store->expects(self::never())->method('createSession');
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('verifyPassword')->willReturn(false);

        try {
            $this->service($store, $hasher)->login(
                'user@example.test',
                'wrong',
                self::DEVICE_ID,
                'device',
                'linux',
            );
            self::fail('An invalid password was accepted.');
        } catch (Problem $problem) {
            self::assertSame(401, $problem->status);
        }
    }

    public function testRefreshReplayRevokesCompromisedSession(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->method('findSessionByRefreshHash')->willReturn(null);
        $store->expects(self::once())
            ->method('revokeRefreshReplay')
            ->with('hash:old-refresh', self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(true);
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')
            ->willReturnCallback(static fn (string $token): string => 'hash:' . $token);

        try {
            $this->service($store, $hasher)->refresh('old-refresh');
            self::fail('A replayed refresh credential was accepted.');
        } catch (Problem $problem) {
            self::assertSame('Credential replay detected', $problem->title);
        }
    }

    public function testRefreshRotatesEverySessionCredential(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->method('findSessionByRefreshHash')->willReturn([
            'id' => self::SESSION_ID,
            'user_id' => self::USER_ID,
            'device_id' => self::DEVICE_ID,
            'installation_id' => '01912345-6789-7abc-bdef-0123456789ab',
            'refresh_token_hash' => 'stored-refresh-hash',
        ]);
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
            ->willReturn(true);
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')
            ->willReturnCallback(static fn (string $token): string => 'hash:' . $token);
        $tokens = $this->tokenGenerator('next-access', 'next-refresh', 'next-csrf');

        $result = $this->service($store, $hasher, $tokens)->refresh('current-refresh');

        self::assertSame(self::USER_ID, $result['userId']);
        self::assertSame('next-access', $result['accessToken']);
        self::assertSame('next-refresh', $result['refreshToken']);
        self::assertSame('next-csrf', $result['csrfToken']);
        self::assertSame(
            '01912345-6789-7abc-bdef-0123456789ab',
            $result['installationId'],
        );
    }

    public function testAuthenticateBuildsIdentityFromSessionAndRoles(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->method('findSessionByAccessHash')->willReturn([
            'user_id' => self::USER_ID,
            'id' => self::SESSION_ID,
            'device_id' => self::DEVICE_ID,
            'active_home_id' => null,
        ]);
        $store->method('platformRoles')->with(self::USER_ID)->willReturn(['billing_operator']);
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')->willReturn('access-hash');

        $identity = $this->service($store, $hasher)->authenticate('access-token');

        self::assertSame(self::USER_ID, $identity->userId);
        self::assertSame(self::SESSION_ID, $identity->sessionId);
        self::assertSame(self::DEVICE_ID, $identity->deviceId);
        self::assertSame(['billing_operator'], $identity->platformRoles);
    }

    public function testPasswordResetChangesPasswordAndRevokesSessionsAtomically(): void
    {
        $transactions = new IdentityTransactionManager();
        $store = $this->createMock(IdentityStore::class);
        $store->method('consumeOneTimeToken')->with(
            'password-reset:homeowner',
            'reset-token-hash',
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn(self::USER_ID);
        $store->expects(self::once())
            ->method('changePassword')
            ->with(
                self::USER_ID,
                'next-password-hash',
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $store->expects(self::once())
            ->method('revokeAllSessions')
            ->with(self::USER_ID, self::isInstanceOf(DateTimeImmutable::class));
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')->willReturn('reset-token-hash');
        $hasher->method('hashPassword')->willReturn('next-password-hash');
        $service = $this->service(
            $store,
            $hasher,
            null,
            null,
            $transactions,
        );

        $service->resetPassword('reset-token', 'A-valid-next-password-123', 'homeowner');

        self::assertSame(1, $transactions->invocations);
    }

    public function testOneTimeCapabilityCannotCrossApplicationBoundary(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::once())->method('consumeOneTimeToken')->with(
            'verify-email:admin',
            'capability-hash',
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn(null);
        $store->expects(self::never())->method('markEmailVerified');
        $hasher = $this->createStub(CredentialHasher::class);
        $hasher->method('hashToken')->willReturn('capability-hash');

        try {
            $this->service($store, $hasher)->verifyEmail('homeowner-capability', 'admin');
            self::fail('A homeowner capability crossed into the administrator application.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
            self::assertSame('Invalid token', $problem->title);
        }
    }

    private function service(
        IdentityStore $store,
        CredentialHasher $hasher,
        ?SecureTokenGenerator $tokens = null,
        ?UuidGenerator $ids = null,
        ?IdentityTransactionManager $transactions = null,
    ): AuthenticationService {
        return new AuthenticationService(
            $store,
            $hasher,
            $this->createStub(AccountNotificationSender::class),
            $ids ?? $this->createStub(UuidGenerator::class),
            new IdentityFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            $transactions ?? new IdentityTransactionManager(),
            $tokens ?? $this->createStub(SecureTokenGenerator::class),
            900,
            2592000,
        );
    }

    private function tokenGenerator(string ...$tokens): SecureTokenGenerator
    {
        $generator = $this->createStub(SecureTokenGenerator::class);
        $generator->method('generate')->willReturnOnConsecutiveCalls(...array_values($tokens));

        return $generator;
    }
}
