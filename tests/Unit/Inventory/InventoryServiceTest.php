<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Inventory;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private const OTHER_HOME_ID = '01912345-6789-7abc-8def-1123456789ab';

    /** @param array{status: string, role: string}|null $membership */
    #[DataProvider('concealedMembershipProvider')]
    public function testItemMasterConcealsAbsentRevokedAndForeignMembership(
        ?array $membership,
        string $membershipHomeId,
    ): void {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::never())->method('itemMaster');
        $service = $this->serviceWithMembership(
            $store,
            $membership,
            $membershipHomeId,
        );

        try {
            $representation = $service->itemMaster(
                $this->identity(),
                self::HOME_ID,
                '',
                null,
                100,
                0,
            );
            self::fail(sprintf(
                'The item master returned a private representation to a non-member: %s',
                json_encode($representation, JSON_THROW_ON_ERROR),
            ));
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
            self::assertNotSame(403, $problem->status);
            self::assertSame('Not found', $problem->title);
            self::assertSame(
                'The requested resource is unavailable.',
                $problem->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{0: array{status: string, role: string}|null, 1: string}>
     */
    public static function concealedMembershipProvider(): iterable
    {
        yield 'absent membership' => [null, self::HOME_ID];
        yield 'revoked membership' => [[
            'status' => 'revoked',
            'role' => HomeAuthorization::MEMBER,
        ], self::HOME_ID];
        yield 'active membership in a foreign home' => [[
            'status' => 'active',
            'role' => HomeAuthorization::MEMBER,
        ], self::OTHER_HOME_ID];
    }

    public function testItemMasterReturnsACompletePageEnvelope(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::once())
            ->method('itemMaster')
            ->with(self::HOME_ID, 'beans', null, 2, 0)
            ->willReturn([
                'items' => [
                    ['packId' => 'pack-1'],
                    ['packId' => 'pack-2'],
                ],
                'total' => 3,
            ]);

        self::assertSame([
            'data' => [
                ['packId' => 'pack-1'],
                ['packId' => 'pack-2'],
            ],
            'pagination' => [
                'limit' => 2,
                'offset' => 0,
                'returned' => 2,
                'total' => 3,
                'hasMore' => true,
                'nextOffset' => 2,
            ],
        ], $this->service($store)->itemMaster(
            $this->identity(),
            self::HOME_ID,
            'beans',
            null,
            2,
            0,
        ));
    }

    public function testViewerIsARealMemberAndCanReadTheItemMaster(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::once())
            ->method('itemMaster')
            ->with(self::HOME_ID, '', null, 100, 0)
            ->willReturn(['items' => [], 'total' => 0]);

        self::assertSame(
            [
                'data' => [],
                'pagination' => [
                    'limit' => 100,
                    'offset' => 0,
                    'returned' => 0,
                    'total' => 0,
                    'hasMore' => false,
                    'nextOffset' => null,
                ],
            ],
            $this->service(
                $store,
                null,
                null,
                HomeAuthorization::VIEWER,
            )->itemMaster(
                $this->identity(),
                self::HOME_ID,
                '',
                null,
                100,
                0,
            ),
        );
    }

    public function testASelectedCatalogPackCanBecomeAHomeProduct(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::once())
            ->method('createHomeProduct')
            ->with(
                self::PRODUCT_ID,
                self::HOME_ID,
                null,
                self::LINE_ID,
                null,
                null,
                null,
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn(self::PRODUCT_ID);

        self::assertSame(
            ['id' => self::PRODUCT_ID],
            $this->service($store, $ids)->addHomeProduct(
                $this->identity(),
                self::HOME_ID,
                null,
                self::LINE_ID,
                null,
                null,
            ),
        );
    }

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

    public function testCancellingAnOpenCountPublishesARevisionWithoutMovements(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::once())
            ->method('countSession')
            ->with(self::HOME_ID, self::SESSION_ID)
            ->willReturn([
                'id' => self::SESSION_ID,
                'status' => 'open',
                'revision' => 4,
                'locationId' => null,
                'notes' => '',
                'scopeComplete' => false,
                'reliability' => 'unassessed',
            ]);
        $store->expects(self::once())
            ->method('cancelCountSession')
            ->with(
                self::HOME_ID,
                self::SESSION_ID,
                4,
                self::USER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(true);
        $store->expects(self::never())->method('countLines');
        $store->expects(self::never())->method('appendMovement');
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::once())
            ->method('put')
            ->with(
                self::HOME_ID,
                self::USER_ID,
                'inventory-count-session',
                self::SESSION_ID,
                5,
                [
                    'locationId' => null,
                    'notes' => '',
                    'scopeComplete' => false,
                    'reliability' => 'unassessed',
                    'status' => 'cancelled',
                ],
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(1);

        self::assertSame(
            ['sessionId' => self::SESSION_ID, 'status' => 'cancelled', 'revision' => 5],
            $this->service($store, null, $changes)->cancelCount(
                $this->identity(),
                self::HOME_ID,
                self::SESSION_ID,
                4,
            ),
        );
    }

    public function testCancellingAnAlreadyCancelledCountIsAnIdempotentReplay(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::once())
            ->method('countSession')
            ->with(self::HOME_ID, self::SESSION_ID)
            ->willReturn(['id' => self::SESSION_ID, 'status' => 'cancelled', 'revision' => 5]);
        $store->expects(self::never())->method('cancelCountSession');
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::never())->method('put');

        self::assertSame(
            ['sessionId' => self::SESSION_ID, 'status' => 'cancelled', 'revision' => 5],
            $this->service($store, null, $changes)->cancelCount(
                $this->identity(),
                self::HOME_ID,
                self::SESSION_ID,
                4,
            ),
        );
    }

    public function testCancellingAnAlreadyCancelledCountRejectsAnUnrelatedStaleRevision(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::once())
            ->method('countSession')
            ->with(self::HOME_ID, self::SESSION_ID)
            ->willReturn(['id' => self::SESSION_ID, 'status' => 'cancelled', 'revision' => 5]);
        $store->expects(self::never())->method('cancelCountSession');
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::never())->method('put');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('changed on another device');
        $this->service($store, null, $changes)->cancelCount(
            $this->identity(),
            self::HOME_ID,
            self::SESSION_ID,
            2,
        );
    }

    public function testCancellingAClosedCountIsAConflictWithoutMovements(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::once())
            ->method('countSession')
            ->with(self::HOME_ID, self::SESSION_ID)
            ->willReturn(['id' => self::SESSION_ID, 'status' => 'closed', 'revision' => 5]);
        $store->expects(self::never())->method('cancelCountSession');
        $store->expects(self::never())->method('appendMovement');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('changed on another device');
        $this->service($store)->cancelCount(
            $this->identity(),
            self::HOME_ID,
            self::SESSION_ID,
            4,
        );
    }

    public function testViewerCannotCancelACountSession(): void
    {
        $store = $this->createMock(InventoryStore::class);
        $store->expects(self::never())->method('countSession');
        $store->expects(self::never())->method('cancelCountSession');
        $store->expects(self::never())->method('appendMovement');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('requested resource is unavailable');
        $this->service($store, null, null, HomeAuthorization::VIEWER)->cancelCount(
            $this->identity(),
            self::HOME_ID,
            self::SESSION_ID,
            4,
        );
    }

    private function service(
        InventoryStore $inventory,
        ?UuidGenerator $ids = null,
        ?ChangeFeedWriter $changes = null,
        string $role = HomeAuthorization::OWNER,
    ): InventoryService {
        return $this->serviceWithMembership(
            $inventory,
            ['status' => 'active', 'role' => $role],
            self::HOME_ID,
            $ids,
            $changes,
        );
    }

    /** @param array{status: string, role: string}|null $membership */
    private function serviceWithMembership(
        InventoryStore $inventory,
        ?array $membership,
        string $membershipHomeId,
        ?UuidGenerator $ids = null,
        ?ChangeFeedWriter $changes = null,
    ): InventoryService {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturnCallback(
            static fn (string $homeId, string $_userId): ?array => $homeId === $membershipHomeId
                ? $membership
                : null,
        );
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
