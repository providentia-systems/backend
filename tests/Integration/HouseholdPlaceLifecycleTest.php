<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DomainException;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;
use Providentia\Purchasing\Infrastructure\Doctrine\DbalPurchasingStore;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use ProvidentiaTest\Support\AccessFixture;

final class HouseholdPlaceLifecycleTest extends TestCase
{
    private Connection $connection;
    private DbalInventoryStore $inventory;
    private DbalPurchasingStore $purchases;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (
            [
                'CREATE TABLE home_locations (id TEXT PRIMARY KEY, home_id TEXT, name TEXT,
                 normalized_name TEXT, kind TEXT, status TEXT, revision INTEGER,
                 created_at TEXT, updated_at TEXT, UNIQUE(home_id, normalized_name))',
                'CREATE TABLE stores (id TEXT PRIMARY KEY, home_id TEXT, name TEXT,
                 normalized_name TEXT, location TEXT, status TEXT, revision INTEGER,
                 created_at TEXT, updated_at TEXT, UNIQUE(home_id, normalized_name, location))',
                'CREATE TABLE stock_count_sessions (id TEXT PRIMARY KEY, home_id TEXT,
                 location_id TEXT, status TEXT)',
                'CREATE TABLE receipts (id TEXT PRIMARY KEY, home_id TEXT, store_id TEXT, status TEXT)',
            ] as $sql
        ) {
            $this->connection->executeStatement($sql);
        }
        $this->inventory = new DbalInventoryStore($this->connection);
        $this->purchases = new DbalPurchasingStore($this->connection);
        $this->at = new DateTimeImmutable('2026-09-12T12:00:00Z');
        $this->inventory->createLocation('location', 'home', 'Pantry', 'pantry', 'pantry', $this->at);
        $this->purchases->createStore('store', 'home', 'Grocer', 'grocer', 'Town', $this->at);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testLocationsRetainClosedCountHistoryAndRejectStaleOrCrossHomeEdits(): void
    {
        $this->connection->insert('stock_count_sessions', [
            'id' => 'count',
            'home_id' => 'home',
            'location_id' => 'location',
            'status' => 'open',
        ]);
        $blocked = $this->inventory->updateLocation(
            'home',
            'location',
            null,
            null,
            null,
            'archived',
            1,
            $this->at,
        );
        self::assertSame('location-in-use', $blocked['status']);
        $location = $this->inventory->location('home', 'location');
        self::assertNotNull($location);
        self::assertSame(1, (int) $location['revision']);
        $this->connection->update('stock_count_sessions', ['status' => 'closed'], ['id' => 'count']);
        $removed = $this->inventory->updateLocation(
            'home',
            'location',
            null,
            null,
            null,
            'archived',
            1,
            $this->at,
        );
        self::assertSame('updated', $removed['status']);
        self::assertSame([], $this->inventory->locations('home'));
        self::assertCount(1, $this->inventory->locations('home', true));
        self::assertSame(
            'location',
            $this->connection->fetchOne('SELECT location_id FROM stock_count_sessions'),
        );
        $restored = $this->inventory->updateLocation(
            'home',
            'location',
            'Cupboard',
            'cupboard',
            'shelf',
            'active',
            2,
            $this->at,
        );
        self::assertSame('updated', $restored['status']);
        self::assertSame('Cupboard', $restored['record']['name']);
        self::assertSame(3, $restored['record']['revision']);
        self::assertSame(
            'revision-conflict',
            $this->inventory->updateLocation(
                'home',
                'location',
                'Lost update',
                'lost update',
                null,
                null,
                1,
                $this->at,
            )['status'],
        );
        self::assertSame(
            'not-found',
            $this->inventory->updateLocation(
                'other-home',
                'location',
                null,
                null,
                null,
                'archived',
                3,
                $this->at,
            )['status'],
        );
    }

    public function testStoresRetainReceiptsAndCannotBeRemovedWhileDraftReferencesRemain(): void
    {
        $this->connection->insert('receipts', [
            'id' => 'receipt',
            'home_id' => 'home',
            'store_id' => 'store',
            'status' => 'draft',
        ]);
        self::assertSame(
            'store-in-use',
            $this->purchases->updateStore('home', 'store', null, null, null, 'archived', 1, $this->at)[
                'status'
            ],
        );
        $this->connection->update('receipts', ['status' => 'committed'], ['id' => 'receipt']);
        self::assertSame(
            'updated',
            $this->purchases->updateStore('home', 'store', null, null, null, 'archived', 1, $this->at)[
                'status'
            ],
        );
        self::assertSame([], $this->purchases->stores('home'));
        self::assertCount(1, $this->purchases->stores('home', true));
        self::assertSame('store', $this->connection->fetchOne('SELECT store_id FROM receipts'));
        $restored = $this->purchases->updateStore(
            'home',
            'store',
            'Market',
            'market',
            'City',
            'active',
            2,
            $this->at,
        );
        self::assertSame('updated', $restored['status']);
        self::assertSame(3, $restored['record']['revision']);
        self::assertSame('City', $restored['record']['location']);
        self::assertSame(
            'revision-conflict',
            $this->purchases->updateStore(
                'home',
                'store',
                'Lost update',
                'lost update',
                null,
                null,
                1,
                $this->at,
            )['status'],
        );
        self::assertNull($this->purchases->store('other-home', 'store'));
    }

    public function testRenamingCannotOverwriteAnotherHouseholdPlace(): void
    {
        $this->purchases->createStore('second', 'home', 'Market', 'market', 'Town', $this->at);
        $this->expectException(DomainException::class);
        $this->purchases->updateStore('home', 'store', 'Market', 'market', null, null, 1, $this->at);
    }

    public function testLocationUpdatePublishesOnlyAfterAuthorizedRevisionBoundWrite(): void
    {
        $authorization = $this->createMock(HomePermissionAuthorizer::class);
        $authorization
            ->expects(self::once())
            ->method('requirePermission')
            ->with(self::isInstanceOf(AuthenticatedIdentity::class), 'home', 'inventory.write')
            ->willReturn([]);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->at);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions
            ->method('transactional')
            ->willReturnCallback(
                fn(callable $work): mixed => $this->connection->transactional(static fn(): mixed => $work()),
            );
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes
            ->expects(self::once())
            ->method('put')
            ->with(
                'home',
                'user',
                'inventory-location',
                'location',
                2,
                ['name' => 'Cupboard', 'kind' => 'shelf', 'status' => 'active'],
                $this->at,
            )
            ->willReturn(1);
        $service = new InventoryService(
            $this->inventory,
            $authorization,
            new SequenceUuidGenerator(),
            $clock,
            $transactions,
            $changes,
            AccessFixture::create(),
        );
        $result = $service->updateLocation(
            new AuthenticatedIdentity('user', 'session', 'device', 'home', []),
            'home',
            'location',
            'Cupboard',
            'shelf',
            'active',
            1,
        );
        self::assertSame(2, $result['revision']);
    }
}
