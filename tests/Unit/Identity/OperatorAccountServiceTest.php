<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Administration\Application\OperatorAccountService;
use Providentia\Billing\Application\OperatorSubscriptionReader;
use Providentia\Home\Application\OperatorHomeAccessReader;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\OperatorAccountControl;
use Providentia\Identity\Application\OperatorIdentityDirectory;
use Providentia\Identity\Application\PlatformRoleService;
use Providentia\Identity\Application\PlatformRoleStore;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;

final class OperatorAccountServiceTest extends TestCase
{
    private const USER_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const TARGET_ID = '01912345-6789-7abc-9def-0123456789ab';
    public function testListReturnsBoundedOffsetPagination(): void
    {
        $store = $this->createMock(OperatorIdentityDirectory::class);
        $store->expects(self::once())
            ->method('operatorAccounts')
            ->with(
                'person',
                'active',
                100,
                3,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                [
                'items' => [['userId' => self::TARGET_ID]],
                'total' => 9,
                ],
            );
        $homes = $this->createMock(OperatorHomeAccessReader::class);
        $homes->expects(self::once())
            ->method('operatorHomeAccess')
            ->with(
                [self::TARGET_ID],
            )
            ->willReturn(
                [
                self::TARGET_ID => [
                    [
                        'homeId' => '01912345-6789-7abc-bdef-0123456789ab',
                        'name' => 'Home',
                        'membershipRole' => 'owner',
                        'membershipStatus' => 'active',
                    ],
                ],
                ],
            );
        $page = $this->service($store, $homes)
            ->list(
                $this->administrator(),
                ' person ',
                'active',
                500,
                3,
            );
        self::assertSame(1, $page['data'][0]['homeCount']);
        self::assertSame(1, $page['pagination']['returned']);
        self::assertSame(4, $page['pagination']['nextOffset']);
        self::assertTrue($page['pagination']['hasMore']);
    }

    public function testStatusChangeRevokesThroughStoreAndReloadsProjection(): void
    {
        $control = $this->createMock(OperatorAccountControl::class);
        $control->expects(self::once())
            ->method('updateOperatorAccountStatus')
            ->with(
                self::isString(),
                self::USER_ID,
                self::TARGET_ID,
                'suspended',
                'Security review',
                2,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                'updated',
            );
        $directory = $this->createMock(OperatorIdentityDirectory::class);
        $directory->expects(self::once())
            ->method('operatorAccount')
            ->with(
                self::TARGET_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                [
                'userId' => self::TARGET_ID,
                'status' => 'suspended',
                'revision' => 3,
                ],
            );
        $account = $this->service($directory, control: $control)
            ->updateStatus(
                $this->administrator(),
                self::TARGET_ID,
                'suspended',
                ' Security review ',
                2,
            );
        self::assertSame('suspended', $account['status']);
        self::assertSame(3, $account['revision']);
    }

    public function testClosedAccountIsReportedAsTerminal(): void
    {
        $control = $this->createStub(OperatorAccountControl::class);
        $control->method('updateOperatorAccountStatus')
            ->willReturn('closed-terminal');
        try {
            $this->service(
                $this->createStub(OperatorIdentityDirectory::class),
                control: $control,
            )
                ->updateStatus(
                    $this->administrator(),
                    self::TARGET_ID,
                    'active',
                    'Requested reopening',
                    4,
                );
            self::fail('A closed account was reopened.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
            self::assertSame('Closed account', $problem->title);
        }
    }

    public function testNonAdministratorCannotListAccounts(): void
    {
        $store = $this->createMock(OperatorIdentityDirectory::class);
        $store->expects(self::never())
            ->method('operatorAccounts');
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('Platform-administrator authority is required.');
        $this->service($store)
            ->list(
                new AuthenticatedIdentity(
                    self::USER_ID,
                    'session',
                    'device',
                    null,
                    [],
                    \ProvidentiaTest\Support\AccessFixture::administratorPermissions([]),
                ),
                '',
                null,
                50,
                0,
            );
    }

    public function testAccountSearchLongerThanTheContractLimitIsRejected(): void
    {
        $directory = $this->createMock(OperatorIdentityDirectory::class);
        $directory->expects(self::never())
            ->method('operatorAccounts');
        try {
            $this->service($directory)
                ->list(
                    $this->administrator(),
                    str_repeat('x', 192),
                    null,
                    50,
                    0,
                );
            self::fail('An overlong account search was truncated.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
        }
    }

    private function service(
        OperatorIdentityDirectory $directory,
        ?OperatorHomeAccessReader $homes = null,
        ?OperatorAccountControl $control = null,
    ): OperatorAccountService {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')
            ->willReturn('01912345-6789-7abc-adef-0123456789ab');
        $clock = new IdentityFixedClock(
            new DateTimeImmutable('2026-08-24T12:00:00+00:00'),
        );
        $transactions = new IdentityTransactionManager();
        $roles = null;
        return new OperatorAccountService(
            $directory,
            $control ?? $this->createStub(OperatorAccountControl::class),
            $homes ?? $this->createStub(OperatorHomeAccessReader::class),
            $this->createStub(OperatorSubscriptionReader::class),
            $ids,
            $clock,
            $transactions,
            $this->createStub(
                \Providentia\Identity\Application\AccountProfileStore::class,
            ),
        );
    }

    private function administrator(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER_ID,
            'session',
            'device',
            null,
            ['platform_administrator'],
            \ProvidentiaTest\Support\AccessFixture::administratorPermissions(['platform_administrator']),
        );
    }
}
