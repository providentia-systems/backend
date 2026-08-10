<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;

final class PlatformRoleHomeIsolationTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';

    /** @return iterable<string, array{string, string}> */
    public static function privateResourcePermissions(): iterable
    {
        yield 'inventory reads' => ['inventory', HomePermission::INVENTORY_READ];
        yield 'inventory writes' => ['inventory', HomePermission::INVENTORY_WRITE];
        yield 'purchase reads' => ['purchases', HomePermission::PURCHASES_READ];
        yield 'purchase writes' => ['purchases', HomePermission::PURCHASES_WRITE];
        yield 'shopping reads' => ['shopping', HomePermission::SHOPPING_READ];
        yield 'shopping writes' => ['shopping', HomePermission::SHOPPING_WRITE];
        yield 'AI settings and history' => ['AI', HomePermission::AI_READ];
        yield 'AI execution and private-media writes' => ['private media', HomePermission::AI_USE];
        yield 'AI and private-media administration' => ['private media', HomePermission::AI_MANAGE];
    }

    #[DataProvider('privateResourcePermissions')]
    public function testPlatformAndCatalogRolesAloneNeverGrantPrivateHomeAccess(
        string $resource,
        string $permission,
    ): void {
        $store = $this->createMock(HomeStore::class);
        $store->expects(self::once())
            ->method('membership')
            ->with(self::HOME_ID, self::USER_ID)
            ->willReturn(null);
        $store->expects(self::never())->method('permissionDecision');
        $identity = new AuthenticatedIdentity(
            self::USER_ID,
            'session',
            'device',
            null,
            [
                CatalogAuthorization::PLATFORM_ADMINISTRATOR,
                CatalogAuthorization::CURATOR,
                CatalogAuthorization::REVIEWER,
            ],
        );

        try {
            (new HomeAuthorization($store))->requirePermission($identity, self::HOME_ID, $permission);
            self::fail(sprintf('Platform roles unexpectedly authorized private %s.', $resource));
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
            self::assertSame('Not found', $problem->title);
        }
    }
}
