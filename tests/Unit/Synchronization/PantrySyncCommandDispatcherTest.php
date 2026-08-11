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
    private const PRODUCT_ID = '01912345-6789-7abc-adef-1123456789ab';
    private const RECEIPT_ID = '01912345-6789-7abc-bdef-1123456789ab';
    private const MOVEMENT_ID = '01912345-6789-7abc-cdef-1123456789ab';

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

    public function testInventoryAdjustmentCarriesTheClientOperationIdToTheLedger(): void
    {
        $inventoryStore = $this->createMock(InventoryStore::class);
        $inventoryStore->method('homeProduct')
            ->with(self::HOME_ID, self::PRODUCT_ID)
            ->willReturn(['id' => self::PRODUCT_ID]);
        $inventoryStore->expects(self::once())
            ->method('appendMovement')
            ->with(
                self::MOVEMENT_ID,
                self::HOME_ID,
                self::PRODUCT_ID,
                'manual-adjustment',
                '-2.5',
                'client-operation',
                self::OPERATION_ID,
                'Damaged items removed',
                self::USER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn([
                'id' => self::MOVEMENT_ID,
                'balance' => '7.5',
                'balanceRevision' => 6,
                'replayed' => false,
            ]);
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn(self::MOVEMENT_ID);

        $result = $this->dispatcher(
            $inventoryStore,
            $this->createStub(PurchasingStore::class),
            $ids,
        )->dispatch(
            $this->identity(),
            self::HOME_ID,
            new SyncCommand(
                self::OPERATION_ID,
                'inventory.adjustment.create',
                self::PRODUCT_ID,
                null,
                '2026-08-04T11:59:00+00:00',
                1,
                ['quantityDelta' => '-2.500', 'reason' => 'Damaged items removed'],
            ),
        );

        self::assertSame(self::MOVEMENT_ID, $result['id']);
        self::assertFalse((bool) $result['replayed']);
    }

    public function testReceiptCommitCarriesTheBaseRevisionAndReplaysCommittedState(): void
    {
        $purchases = $this->createMock(PurchasingStore::class);
        $purchases->expects(self::once())
            ->method('receipt')
            ->with(self::HOME_ID, self::RECEIPT_ID)
            ->willReturn(['id' => self::RECEIPT_ID, 'status' => 'committed', 'revision' => 4]);
        $purchases->expects(self::never())->method('receiptLines');
        $purchases->expects(self::never())->method('markReceiptCommitted');

        $result = $this->dispatcher(
            $this->createStub(InventoryStore::class),
            $purchases,
        )->dispatch(
            $this->identity(),
            self::HOME_ID,
            new SyncCommand(
                self::OPERATION_ID,
                'purchasing.receipt.commit',
                self::RECEIPT_ID,
                3,
                '2026-08-04T11:59:00+00:00',
                1,
                [],
            ),
        );

        self::assertSame(['receiptId' => self::RECEIPT_ID, 'movements' => 0], $result);
    }

    public function testReceiptLineUnresolvedCommandUsesRevisionedPurchasingDecision(): void
    {
        $lineId = self::PRODUCT_ID;
        $draft = [
            'id' => self::RECEIPT_ID,
            'status' => 'draft',
            'revision' => 4,
            'purchaseDate' => '2026-08-04',
            'currency' => 'NAD',
        ];
        $purchases = $this->createMock(PurchasingStore::class);
        $purchases->expects(self::exactly(2))
            ->method('receipt')
            ->with(self::HOME_ID, self::RECEIPT_ID)
            ->willReturnOnConsecutiveCalls($draft, [...$draft, 'revision' => 5]);
        $purchases->expects(self::exactly(2))
            ->method('receiptLine')
            ->with(self::HOME_ID, self::RECEIPT_ID, $lineId)
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => $lineId,
                    'receiptId' => self::RECEIPT_ID,
                    'rawDescription' => 'Unreadable line',
                    'quantity' => '1',
                    'homeProductId' => null,
                    'approvalStatus' => 'unreviewed',
                    'revision' => 2,
                ],
                [
                    'id' => $lineId,
                    'receiptId' => self::RECEIPT_ID,
                    'rawDescription' => 'Unreadable line',
                    'quantity' => '1',
                    'homeProductId' => null,
                    'approvalStatus' => 'unresolved',
                    'revision' => 3,
                ],
            );
        $purchases->expects(self::once())
            ->method('markReceiptLineUnresolved')
            ->with(
                self::HOME_ID,
                self::RECEIPT_ID,
                $lineId,
                2,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(true);

        $result = $this->dispatcher(
            $this->createStub(InventoryStore::class),
            $purchases,
        )->dispatch(
            $this->identity(),
            self::HOME_ID,
            new SyncCommand(
                self::OPERATION_ID,
                'purchasing.receipt-line.unresolve',
                $lineId,
                2,
                '2026-08-04T11:59:00+00:00',
                1,
                ['receiptId' => self::RECEIPT_ID],
            ),
        );

        self::assertSame(
            ['id' => $lineId, 'revision' => 3, 'approvalStatus' => 'unresolved'],
            $result,
        );
    }

    public function testCountCancellationUsesTheAuthoritativeRevisionedService(): void
    {
        $inventory = $this->createMock(InventoryStore::class);
        $inventory->expects(self::once())
            ->method('countSession')
            ->with(self::HOME_ID, self::SESSION_ID)
            ->willReturn([
                'id' => self::SESSION_ID,
                'status' => 'open',
                'revision' => 3,
                'locationId' => null,
                'notes' => '',
                'scopeComplete' => false,
                'reliability' => 'unassessed',
            ]);
        $inventory->expects(self::once())
            ->method('cancelCountSession')
            ->with(
                self::HOME_ID,
                self::SESSION_ID,
                3,
                self::USER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(true);
        $inventory->expects(self::never())->method('appendMovement');

        $result = $this->dispatcher(
            $inventory,
            $this->createStub(PurchasingStore::class),
        )->dispatch(
            $this->identity(),
            self::HOME_ID,
            new SyncCommand(
                self::OPERATION_ID,
                'inventory.count-session.cancel',
                self::SESSION_ID,
                3,
                '2026-08-04T11:59:00+00:00',
                1,
                [],
            ),
        );

        self::assertSame(
            ['sessionId' => self::SESSION_ID, 'status' => 'cancelled', 'revision' => 4],
            $result,
        );
    }

    private function dispatcher(
        InventoryStore $inventoryStore,
        PurchasingStore $purchasingStore,
        ?UuidGenerator $ids = null,
    ): PantrySyncCommandDispatcher {
        $homeStore = $this->createStub(HomeStore::class);
        $homeStore->method('membership')->willReturn([
            'status' => 'active',
            'role' => HomeAuthorization::MEMBER,
        ]);
        $authorization = new HomeAuthorization($homeStore);
        $clock = new FixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00'));
        $transactions = new PantryImmediateTransactionManager();
        $ids ??= $this->createStub(UuidGenerator::class);
        $inventory = new InventoryService(
            $inventoryStore,
            $authorization,
            $ids,
            $clock,
            $transactions,
        );
        $purchasing = new PurchasingService(
            $purchasingStore,
            $inventory,
            $authorization,
            $ids,
            $clock,
            $transactions,
        );
        $shopping = new ShoppingService(
            $this->createStub(ShoppingStore::class),
            $authorization,
            new LegacySuggestionPolicy(),
            $ids,
            $clock,
            $transactions,
        );

        return new PantrySyncCommandDispatcher($inventory, $purchasing, $shopping);
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER_ID,
            self::SESSION_ID,
            self::DEVICE_ID,
            self::HOME_ID,
            [],
        );
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
