<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\IdentityStore;
use Providentia\Identity\Application\PlatformAdministratorService;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;

final class PlatformAdministratorServiceTest extends TestCase
{
    private const USER_ID = '01912345-6789-7abc-8def-0123456789ab';

    public function testPendingGrantAndNotificationAreOneTransaction(): void
    {
        $transactions = new IdentityTransactionManager();
        $store = $this->createMock(IdentityStore::class);
        $store->expects(self::once())->method('grantPlatformAdministrator')->willReturn([
            'changed' => true,
            'id' => '01912345-6789-7abc-9def-0123456789ab',
            'email' => 'next@example.test',
            'userId' => null,
            'status' => 'pending',
            'revision' => 1,
            'grantedByUserId' => self::USER_ID,
            'createdAt' => '2026-08-09T12:00:00+00:00',
            'activatedAt' => null,
        ]);
        $notifications = $this->createMock(AccountNotificationSender::class);
        $notifications->expects(self::once())->method('sendPlatformAdministratorInvitation')
            ->with('next@example.test')
            ->willReturnCallback(static function () use ($transactions): void {
                self::assertTrue($transactions->active);
            });

        $result = $this->service($store, $notifications, $transactions)->grant(
            $this->administrator(),
            ' Next@Example.Test ',
        );

        self::assertSame('pending', $result['status']);
        self::assertArrayNotHasKey('changed', $result);
        self::assertSame(1, $transactions->invocations);
    }

    public function testIdempotentPendingGrantDoesNotResendInvitation(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('grantPlatformAdministrator')->willReturn([
            'changed' => false,
            'id' => '01912345-6789-7abc-9def-0123456789ab',
            'email' => 'next@example.test',
            'userId' => null,
            'status' => 'pending',
            'revision' => 1,
            'grantedByUserId' => self::USER_ID,
            'createdAt' => '2026-08-09T12:00:00+00:00',
            'activatedAt' => null,
        ]);
        $notifications = $this->createMock(AccountNotificationSender::class);
        $notifications->expects(self::never())->method('sendPlatformAdministratorInvitation');

        $this->service($store, $notifications)->grant($this->administrator(), 'next@example.test');
    }

    public function testLastAdministratorSafeguardIsReportedAsConflict(): void
    {
        $store = $this->createStub(IdentityStore::class);
        $store->method('revokePlatformAdministrator')->willReturn('last-administrator');

        try {
            $this->service($store)->revoke(
                $this->administrator(),
                self::USER_ID,
                1,
            );
            self::fail('The final administrator was revoked.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
            self::assertSame('Last administrator safeguard', $problem->title);
        }
    }

    private function service(
        IdentityStore $store,
        ?AccountNotificationSender $notifications = null,
        ?IdentityTransactionManager $transactions = null,
    ): PlatformAdministratorService {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01912345-6789-7abc-9def-0123456789ab');

        return new PlatformAdministratorService(
            $store,
            $ids,
            new IdentityFixedClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00')),
            $transactions ?? new IdentityTransactionManager(),
            $notifications ?? $this->createStub(AccountNotificationSender::class),
        );
    }

    private function administrator(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER_ID,
            '01912345-6789-7abc-9def-0123456789ab',
            '01912345-6789-7abc-adef-0123456789ab',
            null,
            ['platform_administrator'],
        );
    }
}
