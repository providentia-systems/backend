<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\PlatformRoleService;
use Providentia\Identity\Application\PlatformRoleStore;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;

final class PlatformRoleServiceTest extends TestCase
{
    private const USER_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const TARGET_ID = '01912345-6789-7abc-9def-0123456789ab';

    public function testAdministratorGrantIsRevisionBound(): void
    {
        $store = $this->createMock(PlatformRoleStore::class);
        $store->expects(self::once())->method('changePlatformRole')->with(
            self::isType('string'),
            self::USER_ID,
            self::TARGET_ID,
            PlatformRoleService::CATALOG_CURATOR,
            true,
            4,
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn('updated');

        $this->service($store)->grant(
            $this->administrator(),
            self::TARGET_ID,
            PlatformRoleService::CATALOG_CURATOR,
            4,
        );
    }

    public function testOwnerCliUsesVerifiedEmailWithoutLoadingHouseholdDetail(): void
    {
        $store = $this->createMock(PlatformRoleStore::class);
        $store->expects(self::once())->method('verifiedAccountByEmail')
            ->with('curator@example.test')
            ->willReturn(['userId' => self::TARGET_ID, 'revision' => 7]);
        $store->expects(self::once())->method('changePlatformRole')->willReturn('updated');

        $result = $this->service($store)->changeVerifiedEmail(
            ' Curator@Example.Test ',
            PlatformRoleService::CATALOG_REVIEWER,
            true,
        );

        self::assertSame(['userId' => self::TARGET_ID, 'revision' => 8], $result);
    }

    public function testOwnerCliReportsAnUnchangedRevisionForAnIdempotentRoleRequest(): void
    {
        $store = $this->createStub(PlatformRoleStore::class);
        $store->method('verifiedAccountByEmail')->willReturn([
            'userId' => self::TARGET_ID,
            'revision' => 7,
        ]);
        $store->method('changePlatformRole')->willReturn('unchanged');

        $result = $this->service($store)->changeVerifiedEmail(
            'curator@example.test',
            PlatformRoleService::CATALOG_REVIEWER,
            true,
        );

        self::assertSame(['userId' => self::TARGET_ID, 'revision' => 7], $result);
    }

    public function testFinalAdministratorSafeguardIsAConflict(): void
    {
        $store = $this->createStub(PlatformRoleStore::class);
        $store->method('changePlatformRole')->willReturn('last-administrator');

        try {
            $this->service($store)->revoke(
                $this->administrator(),
                self::USER_ID,
                PlatformRoleService::ADMINISTRATOR,
                1,
            );
            self::fail('The final administrator role was revoked.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
            self::assertSame('Last administrator safeguard', $problem->title);
        }
    }

    public function testNonAdministratorCannotGrantPlatformRoles(): void
    {
        $store = $this->createMock(PlatformRoleStore::class);
        $store->expects(self::never())->method('changePlatformRole');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('Platform-administrator authority is required.');
        $this->service($store)->grant(
            new AuthenticatedIdentity(self::USER_ID, 'session', 'device', null, []),
            self::TARGET_ID,
            PlatformRoleService::BILLING_OPERATOR,
            1,
        );
    }

    private function service(PlatformRoleStore $store): PlatformRoleService
    {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01912345-6789-7abc-adef-0123456789ab');

        return new PlatformRoleService(
            $store,
            $ids,
            new IdentityFixedClock(new DateTimeImmutable('2026-08-24T12:00:00+00:00')),
            new IdentityTransactionManager(),
        );
    }

    private function administrator(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER_ID,
            'session',
            'device',
            null,
            [PlatformRoleService::ADMINISTRATOR],
        );
    }
}
