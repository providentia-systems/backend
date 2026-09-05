<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Billing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Billing\Application\BillingAuthorization;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;

final class BillingAuthorizationTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function rejectedHomePermissions(): iterable
    {
        yield 'member cannot read financial details' => ['member', BillingAuthorization::BILLING_READ];
        yield 'viewer cannot read financial details' => ['viewer', BillingAuthorization::BILLING_READ];
        yield 'manager cannot change a subscription' => ['manager', BillingAuthorization::BILLING_MANAGE];
    }
    #[DataProvider('rejectedHomePermissions')]
    public function testHomeBillingPermissionDefaultsAreLeastPrivilege(
        string $role,
        string $permission,
    ): void {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')
            ->willReturn(['status' => 'active', 'role' => $role]);
        $authorization = new BillingAuthorization(
            new HomeAuthorization(
                $homes,
                \ProvidentiaTest\Support\AccessFixture::create(),
            ),
        );
        try {
            if ($permission === BillingAuthorization::BILLING_READ) {
                $authorization->requireHomeRead($this->identity(), 'home-1');
            } else {
                $authorization->requireHomeManage($this->identity(), 'home-1');
            }
            self::fail(
                'A least-privilege billing permission was bypassed.',
            );
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
        }
    }

    public function testUnprivilegedPlatformUserCannotManagePlans(): void
    {
        $homes = $this->createStub(HomeStore::class);
        $authorization = new BillingAuthorization(
            new HomeAuthorization(
                $homes,
                \ProvidentiaTest\Support\AccessFixture::create(),
            ),
        );
        $this->expectException(Problem::class);
        $authorization->requireOperator($this->identity());
    }

    public function testPersistedHomePolicyCanGrantBillingReadToAMember(): void
    {
        $homes = $this->createMock(HomeStore::class);
        $homes->method('membership')
            ->willReturn(['status' => 'active', 'role' => 'member']);
        $homes->expects(self::once())
            ->method('permissionDecision')
            ->with(
                'home-1',
                'member',
                BillingAuthorization::BILLING_READ,
            )
            ->willReturn(
                true,
            );
        $authorization = new BillingAuthorization(
            new HomeAuthorization(
                $homes,
                \ProvidentiaTest\Support\AccessFixture::create(),
            ),
        );
        $authorization->requireHomeRead($this->identity(), 'home-1');
        self::addToAssertionCount(1);
    }

    public function testPersistedHomePolicyCanRemoveManagerBillingRead(): void
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')
            ->willReturn(['status' => 'active', 'role' => 'manager']);
        $homes->method('permissionDecision')
            ->willReturn(false);
        $authorization = new BillingAuthorization(
            new HomeAuthorization(
                $homes,
                \ProvidentiaTest\Support\AccessFixture::create(),
            ),
        );
        $this->expectException(Problem::class);
        $authorization->requireHomeRead($this->identity(), 'home-1');
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            'user-1',
            'session-1',
            'device-1',
            null,
            [],
            \ProvidentiaTest\Support\AccessFixture::administratorPermissions([]),
        );
    }
}
