<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Home;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeService;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Application\SecureTokenGenerator;

final class HomeServiceTest extends TestCase
{
    public function testRoleChangeAndAuditExecuteInsideOneTransaction(): void
    {
        $transactions = new RecordingTransactionManager();
        $homes = $this->createMock(HomeStore::class);
        $homes->expects(self::exactly(2))
            ->method('membership')
            ->willReturnOnConsecutiveCalls(
                ['status' => 'active', 'role' => HomeAuthorization::OWNER],
                ['status' => 'active', 'role' => HomeAuthorization::MEMBER, 'revision' => 3],
            );
        $homes->expects(self::once())
            ->method('changeMembershipRole')
            ->willReturnCallback(function () use ($transactions): bool {
                self::assertTrue($transactions->active);

                return true;
            });
        $homes->expects(self::once())
            ->method('recordAudit')
            ->willReturnCallback(function (
                string $id,
                string $actorUserId,
                string $action,
                string $targetType,
                string $targetId,
                string $homeId,
                string $detailsJson,
                DateTimeImmutable $at,
            ) use ($transactions): void {
                self::assertTrue($transactions->active);
                self::assertSame('01912345-6789-7abc-8def-2123456789ab', $id);
                self::assertSame('01912345-6789-7abc-9def-0123456789ab', $actorUserId);
                self::assertSame('home.membership.role-changed', $action);
                self::assertSame('home_membership', $targetType);
                self::assertSame('01912345-6789-7abc-9def-1123456789ab', $targetId);
                self::assertSame('01912345-6789-7abc-8def-0123456789ab', $homeId);
                self::assertJson($detailsJson);
                self::assertSame('2026-07-30T12:00:00+00:00', $at->format(DATE_ATOM));
            });

        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01912345-6789-7abc-8def-2123456789ab');
        $service = new HomeService(
            $homes,
            new HomeAuthorization($homes),
            $this->createStub(IdentityStore::class),
            $this->createStub(CredentialHasher::class),
            $this->createStub(AccountNotificationSender::class),
            $ids,
            new HomeFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            $transactions,
            $this->createStub(SecureTokenGenerator::class),
        );

        $service->changeRole(
            new AuthenticatedIdentity(
                '01912345-6789-7abc-9def-0123456789ab',
                '01912345-6789-7abc-adef-0123456789ab',
                '01912345-6789-7abc-bdef-0123456789ab',
                null,
                [],
            ),
            '01912345-6789-7abc-8def-0123456789ab',
            '01912345-6789-7abc-9def-1123456789ab',
            HomeAuthorization::VIEWER,
            3,
        );

        self::assertSame(1, $transactions->invocations);
        self::assertFalse($transactions->active);
    }
}
