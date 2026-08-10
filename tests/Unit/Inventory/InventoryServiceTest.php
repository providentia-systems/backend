<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Inventory;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;

final class InventoryServiceTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const PRODUCT_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const SESSION_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const LINE_ID = '01912345-6789-7abc-cdef-0123456789ab';
    private const MOVEMENT_ID = '01912345-6789-7abc-ddef-0123456789ab';
    private const SECOND_MOVEMENT_ID = '01912345-6789-7abc-edef-0123456789ab';

    public function testManualAdjustmentReturnsTheStoredReplayWithoutRepublishingAChange(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::exactly(2))
            ->method('homeProduct')
            ->with(self::HOME_ID, self::PRODUCT_ID)
            ->willReturn(['id' => self::PRODUCT_ID]);
        $store->expects(self::exactly(2))
            ->method('appendMovement')
            ->with(
                self::anything(),
                self::HOME_ID,
                self::PRODUCT_ID,
                'manual-adjustment',
                '1.5',
                'client-operation',
                'client-operation-1',
                'Counted after delivery',
                self::USER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => self::MOVEMENT_ID,
                    'balance' => '3.5',
                    'balanceRevision' => 2,
                    'replayed' => false,
                ],
                [
                    'id' => self::MOVEMENT_ID,
                    'balance' => '3.5',
                    'balanceRevision' => 2,
                    'replayed' => true,
                ],
            );
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::once())
            ->method('put')
            ->with(
                self::HOME_ID,
                self::USER_ID,
                'inventory-balance',
                self::PRODUCT_ID,
                2,
                [
                    'homeProductId' => self::PRODUCT_ID,
                    'quantity' => '3.5',
                    'lastMovementId' => self::MOVEMENT_ID,
                ],
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(1);
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturnOnConsecutiveCalls(self::MOVEMENT_ID, self::SECOND_MOVEMENT_ID);
        $service = $this->service($store, $ids, $changes);

        $first = $service->manualAdjustment(
            $this->identity(),
            self::HOME_ID,
            self::PRODUCT_ID,
            '1.5000',
            'Counted after delivery',
            'client-operation-1',
        );
        $replay = $service->manualAdjustment(
            $this->identity(),
            self::HOME_ID,
            self::PRODUCT_ID,
            '1.5000',
            'Counted after delivery',
            'client-operation-1',
        );

        self::assertFalse((bool) $first['replayed']);
        self::assertTrue((bool) $replay['replayed']);
        self::assertSame(self::MOVEMENT_ID, $replay['id']);
    }

    public function testManualAdjustmentCannotReferenceAProductFromAnotherHome(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::once())->method('homeProduct')->with(self::HOME_ID, self::PRODUCT_ID)->willReturn(null);
        $store->expects(self::never())->method('appendMovement');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('requested resource is unavailable');
        $this->service($store)->manualAdjustment(
            $this->identity(),
            self::HOME_ID,
            self::PRODUCT_ID,
            '1',
            'Manual correction',
            'client-operation-2',
        );
    }

    public function testCountLineRevisionConflictDoesNotPublishAProjection(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->method('countSession')->with(self::HOME_ID, self::SESSION_ID)->willReturn([
            'id' => self::SESSION_ID,
            'status' => 'open',
            'revision' => 4,
        ]);
        $store->expects(self::once())
            ->method('saveCountLine')
            ->with(
                self::LINE_ID,
                self::HOME_ID,
                self::SESSION_ID,
                self::PRODUCT_ID,
                '4',
                null,
                'manual',
                '',
                self::USER_ID,
                2,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(false);
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::never())->method('put');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('changed on another device');
        $this->service($store, null, $changes)->recordCount(
            $this->identity(),
            self::HOME_ID,
            self::SESSION_ID,
            self::LINE_ID,
            self::PRODUCT_ID,
            '4.000',
            null,
            'manual',
            '',
            2,
        );
    }

    public function testClosingAnAlreadyClosedCountIsAnIdempotentReplay(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::once())
            ->method('countSession')
            ->with(self::HOME_ID, self::SESSION_ID)
            ->willReturn(['id' => self::SESSION_ID, 'status' => 'closed', 'revision' => 6]);
        $store->expects(self::never())->method('countLines');
        $store->expects(self::never())->method('appendMovement');
        $store->expects(self::never())->method('closeCountSession');

        self::assertSame(
            ['sessionId' => self::SESSION_ID, 'movements' => 0],
            $this->service($store)->closeCount($this->identity(), self::HOME_ID, self::SESSION_ID, 5),
        );
    }

    private function service(
        InventoryStore $inventory,
        ?UuidGenerator $ids = null,
        ?ChangeFeedWriter $changes = null,
    ): InventoryService {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn([
            'status' => 'active',
            'role' => HomeAuthorization::OWNER,
        ]);
        if ($ids === null) {
            $ids = $this->createStub(UuidGenerator::class);
            $ids->method('generate')->willReturn(self::MOVEMENT_ID);
        }

        return new InventoryService(
            $inventory,
            new HomeAuthorization($homes),
            $ids,
            new HomeFixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00')),
            new RecordingTransactionManager(),
            $changes,
        );
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(self::USER_ID, 'session', 'device', self::HOME_ID, []);
    }
}
