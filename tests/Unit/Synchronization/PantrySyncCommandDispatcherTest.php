<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Purchasing\Application\PurchasingStore;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingService;
use Providentia\Shopping\Application\ShoppingStore;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;
use Providentia\Synchronization\Application\PantrySyncCommandDispatcher;
use Providentia\Synchronization\Application\SyncCommand;

final class PantrySyncCommandDispatcherTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const SESSION_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const DEVICE_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const OPERATION_ID = '01912345-6789-7abc-8def-1123456789ab';
    private const LIST_ID = '01912345-6789-7abc-9def-1123456789ab';

    public function testShoppingCommandUsesTheAuthoritativeApplicationServiceAndClientId(): void
    {
        $homeStore = $this->createStub(HomeStore::class);
        $homeStore->method('membership')->willReturn([
            'status' => 'active',
            'role' => HomeAuthorization::MEMBER,
        ]);
        $authorization = new HomeAuthorization($homeStore);
        $clock = new FixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00'));
        $transactions = new PantryImmediateTransactionManager();
        $ids = $this->createStub(UuidGenerator::class);
        $inventory = new InventoryService(
            $this->createStub(InventoryStore::class),
            $authorization,
            $ids,
            $clock,
            $transactions,
        );
        $purchasing = new PurchasingService(
            $this->createStub(PurchasingStore::class),
            $inventory,
            $authorization,
            $ids,
            $clock,
            $transactions,
        );
        $shoppingStore = $this->createMock(ShoppingStore::class);
        $shoppingStore->expects(self::once())
            ->method('createList')
            ->with(
                self::LIST_ID,
                self::HOME_ID,
                'Weekly shop',
                'manual',
                self::USER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::once())
            ->method('put')
            ->with(
                self::HOME_ID,
                self::USER_ID,
                'shopping-list',
                self::LIST_ID,
                1,
                ['name' => 'Weekly shop', 'kind' => 'manual', 'status' => 'open'],
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $shopping = new ShoppingService(
            $shoppingStore,
            $authorization,
            new LegacySuggestionPolicy(),
            $ids,
            $clock,
            $transactions,
            $changes,
        );
        $dispatcher = new PantrySyncCommandDispatcher($inventory, $purchasing, $shopping);

        $result = $dispatcher->dispatch(
            new AuthenticatedIdentity(
                self::USER_ID,
                self::SESSION_ID,
                self::DEVICE_ID,
                self::HOME_ID,
                [],
            ),
            self::HOME_ID,
            new SyncCommand(
                self::OPERATION_ID,
                'shopping.list.create',
                self::LIST_ID,
                null,
                '2026-08-04T11:59:00+00:00',
                1,
                ['name' => 'Weekly shop', 'kind' => 'manual'],
            ),
        );

        self::assertSame(['id' => self::LIST_ID, 'revision' => 1], $result);
    }
}

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- focused test double belongs with this unit.
final class PantryImmediateTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
