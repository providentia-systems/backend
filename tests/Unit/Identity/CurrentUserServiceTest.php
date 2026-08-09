<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\CurrentUserService;
use Providentia\Identity\Application\IdentityStore;
use Providentia\SharedKernel\Application\Problem;

final class CurrentUserServiceTest extends TestCase
{
    private const USER_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const SESSION_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const HOME_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const STALE_HOME_ID = '01912345-6789-7abc-bdef-0123456789ab';

    public function testBootstrapReturnsCurrentSessionHomesInvitationsAndClearsStaleHome(): void
    {
        $store = $this->createMock(IdentityStore::class);
        $store->method('profile')->willReturn([
            'status' => 'active',
            'email' => 'person@example.test',
            'emailVerifiedAt' => '2026-08-09T10:00:00+00:00',
            'displayName' => 'Person',
            'locale' => 'en-NA',
            'timezone' => 'Africa/Windhoek',
        ]);
        $store->method('listSessions')->willReturn([[
            'id' => self::SESSION_ID,
            'deviceId' => '01912345-6789-7abc-cdef-0123456789ab',
            'transport' => 'native',
            'accessExpiresAt' => '2026-08-09T12:15:00+00:00',
            'refreshExpiresAt' => '2026-10-08T12:00:00+00:00',
            'idleExpiresAt' => '2026-10-08T12:00:00+00:00',
            'createdAt' => '2026-08-09T12:00:00+00:00',
            'lastSeenAt' => '2026-08-09T12:00:00+00:00',
        ]]);
        $store->expects(self::once())->method('clearActiveHome')->with(
            self::USER_ID,
            self::STALE_HOME_ID,
            self::isInstanceOf(DateTimeImmutable::class),
        );
        $homes = $this->createStub(HomeStore::class);
        $homes->method('listForUser')->willReturn([[
            'id' => self::HOME_ID,
            'name' => 'My home',
            'role' => 'owner',
            'defaultLocale' => 'en-NA',
            'defaultCurrency' => 'NAD',
            'defaultTimezone' => 'Africa/Windhoek',
            'status' => 'active',
            'revision' => 1,
        ]]);
        $homes->method('pendingInvitationsForEmail')->willReturn([[
            'id' => '01912345-6789-7abc-ddef-0123456789ab',
            'homeId' => self::HOME_ID,
            'homeName' => 'My home',
            'inviterUserId' => self::USER_ID,
            'inviterDisplayName' => 'Person',
            'role' => 'viewer',
            'status' => 'pending',
            'expiresAt' => '2026-08-16T12:00:00+00:00',
            'revision' => 1,
        ]]);
        $service = new CurrentUserService(
            $store,
            $homes,
            new IdentityFixedClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00')),
        );

        $result = $service->bootstrap($this->identity());

        self::assertNull($result['activeHomeId']);
        $currentSession = $result['currentSession'];
        self::assertIsArray($currentSession);
        self::assertTrue($currentSession['current']);
        $homesResult = $result['homes'];
        self::assertIsArray($homesResult);
        self::assertCount(1, $homesResult);
        $invitations = $result['pendingInvitations'];
        self::assertIsArray($invitations);
        self::assertCount(1, $invitations);
        self::assertSame(['platform_administrator'], $result['platformRoles']);
    }

    public function testMissingProfileRejectsBootstrap(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('profile')->willReturn(null);
        $service = new CurrentUserService(
            $store,
            $this->createStub(HomeStore::class),
            new IdentityFixedClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00')),
        );

        $this->expectException(Problem::class);
        $service->bootstrap($this->identity());
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER_ID,
            self::SESSION_ID,
            '01912345-6789-7abc-cdef-0123456789ab',
            self::STALE_HOME_ID,
            ['platform_administrator'],
        );
    }
}
