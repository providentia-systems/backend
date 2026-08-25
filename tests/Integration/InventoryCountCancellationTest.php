<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalChangeFeedWriter;

final class InventoryCountCancellationTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const OTHER_HOME_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const SESSION_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const PRODUCT_ID = '01912345-6789-7abc-cdef-0123456789ab';
    private const LINE_ID = '01912345-6789-7abc-ddef-0123456789ab';
    private const CREATED_SESSION_ID = '01912345-6789-7abc-9def-2123456789ab';

    private Connection $connection;
    private InventoryService $service;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ($this->schema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->connection->insert('stock_count_sessions', [
            'id' => self::SESSION_ID,
            'home_id' => self::HOME_ID,
            'location_id' => null,
            'status' => 'open',
            'notes' => 'Shelf count',
            'scope_complete' => 0,
            'reliability' => 'unassessed',
            'revision' => 4,
            'opened_by_user_id' => self::USER_ID,
            'opened_at' => '2026-08-11 08:00:00',
            'closed_by_user_id' => null,
            'closed_at' => null,
            'created_at' => '2026-08-11 08:00:00',
            'updated_at' => '2026-08-11 08:00:00',
        ]);
        $this->connection->insert('home_products', [
            'id' => self::PRODUCT_ID,
            'home_id' => self::HOME_ID,
            'status' => 'active',
        ]);
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn([
            'status' => 'active',
            'role' => HomeAuthorization::OWNER,
        ]);
        $this->service = new InventoryService(
            new DbalInventoryStore($this->connection),
            new HomeAuthorization($homes),
            new SequenceUuidGenerator(),
            new CountCancellationFixedClock(new DateTimeImmutable('2026-08-11T10:00:00+00:00')),
            new CountCancellationTransactionManager($this->connection),
            new DbalChangeFeedWriter($this->connection, new SequenceUuidGenerator()),
        );
    }

    public function testCancellationPersistsAndPublishesExactlyOnceWithoutCreatingMovements(): void
    {
        $first = $this->service->cancelCount(
            $this->identity(),
            self::HOME_ID,
            self::SESSION_ID,
            4,
        );
        $replay = $this->service->cancelCount(
            $this->identity(),
            self::HOME_ID,
            self::SESSION_ID,
            4,
        );

        self::assertSame(
            ['sessionId' => self::SESSION_ID, 'status' => 'cancelled', 'revision' => 5],
            $first,
        );
        self::assertSame($first, $replay);
        $session = $this->connection->fetchAssociative(
            'SELECT status, revision, closed_by_user_id, closed_at
             FROM stock_count_sessions WHERE home_id = :home AND id = :id',
            ['home' => self::HOME_ID, 'id' => self::SESSION_ID],
        );
        if ($session === false) {
            self::fail('The cancelled count session is missing.');
        }
        self::assertSame('cancelled', $session['status']);
        self::assertSame(5, (int) $session['revision']);
        self::assertSame(self::USER_ID, $session['closed_by_user_id']);
        self::assertSame('2026-08-11 10:00:00', $session['closed_at']);
        self::assertSame(0, $this->rowCount('stock_movements'));
        self::assertSame(1, $this->rowCount('change_log'));
        self::assertSame(1, $this->rowCount('outbox_messages'));

        $change = $this->connection->fetchAssociative(
            'SELECT home_id, entity_type, entity_id, operation_type, revision,
                    payload_schema_version, payload_json, changed_by_user_id
             FROM change_log',
        );
        if ($change === false) {
            self::fail('The cancellation change-feed row is missing.');
        }
        self::assertSame(self::HOME_ID, $change['home_id']);
        self::assertSame('inventory-count-session', $change['entity_type']);
        self::assertSame(self::SESSION_ID, $change['entity_id']);
        self::assertSame('put', $change['operation_type']);
        self::assertSame(5, (int) $change['revision']);
        self::assertSame(1, (int) $change['payload_schema_version']);
        self::assertSame(self::USER_ID, $change['changed_by_user_id']);
        self::assertSame([
            'locationId' => null,
            'notes' => 'Shelf count',
            'scopeComplete' => false,
            'reliability' => 'unassessed',
            'status' => 'cancelled',
        ], json_decode((string) $change['payload_json'], true, 32, JSON_THROW_ON_ERROR));
    }

    public function testCancellationCannotCrossHomeBoundary(): void
    {
        try {
            $this->service->cancelCount(
                $this->identity(),
                self::OTHER_HOME_ID,
                self::SESSION_ID,
                4,
            );
            self::fail('A count session was cancelled through another home.');
        } catch (Problem $problem) {
            self::assertSame(404, $problem->status);
        }

        self::assertSame('open', $this->connection->fetchOne(
            'SELECT status FROM stock_count_sessions WHERE home_id = :home AND id = :id',
            ['home' => self::HOME_ID, 'id' => self::SESSION_ID],
        ));
        self::assertSame(0, $this->rowCount('change_log'));
        self::assertSame(0, $this->rowCount('stock_movements'));
    }

    public function testCountLineCreationUpdateAndStaleRevisionAreCompareAndSwapSafe(): void
    {
        $created = $this->service->recordCount(
            $this->identity(),
            self::HOME_ID,
            self::SESSION_ID,
            self::LINE_ID,
            self::PRODUCT_ID,
            '4.0000',
            '0.8750',
            'photo-confirmed',
            'First pass',
            0,
        );

        self::assertSame([
            'id' => self::LINE_ID,
            'homeProductId' => self::PRODUCT_ID,
            'quantity' => '4',
            'confidence' => '0.8750',
            'source' => 'photo-confirmed',
            'notes' => 'First pass',
            'status' => 'confirmed',
            'revision' => 1,
        ], $created);

        $updated = $this->service->recordCount(
            $this->identity(),
            self::HOME_ID,
            self::SESSION_ID,
            self::LINE_ID,
            self::PRODUCT_ID,
            '5.25',
            null,
            'manual',
            'Second pass',
            1,
        );

        self::assertSame([
            'id' => self::LINE_ID,
            'homeProductId' => self::PRODUCT_ID,
            'quantity' => '5.25',
            'confidence' => null,
            'source' => 'manual',
            'notes' => 'Second pass',
            'status' => 'confirmed',
            'revision' => 2,
        ], $updated);

        try {
            $this->service->recordCount(
                $this->identity(),
                self::HOME_ID,
                self::SESSION_ID,
                self::LINE_ID,
                self::PRODUCT_ID,
                '99',
                null,
                'manual',
                'Stale device',
                1,
            );
            self::fail('A stale stock-count line revision was accepted.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
            self::assertSame('Revision conflict', $problem->title);
        }

        $persisted = $this->connection->fetchAssociative(
            'SELECT quantity, source, notes, revision FROM stock_count_lines WHERE id = :id',
            ['id' => self::LINE_ID],
        );
        if ($persisted === false) {
            self::fail('The stock-count line is missing.');
        }
        self::assertSame('5.25', $persisted['quantity']);
        self::assertSame('manual', $persisted['source']);
        self::assertSame('Second pass', $persisted['notes']);
        self::assertSame(2, (int) $persisted['revision']);
        self::assertSame(6, (int) $this->connection->fetchOne(
            'SELECT revision FROM stock_count_sessions WHERE id = :id',
            ['id' => self::SESSION_ID],
        ));
        self::assertSame(4, $this->rowCount('change_log'));
        self::assertSame(4, $this->rowCount('outbox_messages'));
    }

    public function testCreateListAndCloseReturnPersistedContractCompleteSessions(): void
    {
        $created = $this->service->startCount(
            $this->identity(),
            self::HOME_ID,
            null,
            'Second shelf',
            false,
            'unassessed',
            self::CREATED_SESSION_ID,
        );

        self::assertSame(self::CREATED_SESSION_ID, $created['id']);
        self::assertSame(self::HOME_ID, $created['homeId']);
        self::assertSame('open', $created['status']);
        self::assertSame(1, $created['revision']);
        self::assertSame([], $created['lines']);

        $sessions = $this->service->countSessions($this->identity(), self::HOME_ID, 50, 0);
        self::assertCount(2, $sessions);
        foreach ($sessions as $session) {
            self::assertSame(self::HOME_ID, $session['homeId']);
            self::assertIsInt($session['revision']);
            self::assertContains($session['status'], ['open', 'closed', 'cancelled']);
        }

        $this->connection->insert('stock_count_lines', [
            'id' => self::LINE_ID,
            'home_id' => self::HOME_ID,
            'session_id' => self::SESSION_ID,
            'home_product_id' => self::PRODUCT_ID,
            'quantity' => '0',
            'confidence' => null,
            'source' => 'manual',
            'notes' => 'Confirmed empty',
            'status' => 'confirmed',
            'revision' => 1,
            'counted_by_user_id' => self::USER_ID,
            'created_at' => '2026-08-11 09:00:00',
            'updated_at' => '2026-08-11 09:00:00',
        ]);

        $closed = $this->service->closeCount(
            $this->identity(),
            self::HOME_ID,
            self::SESSION_ID,
            4,
        );

        self::assertSame(self::SESSION_ID, $closed['id']);
        self::assertSame(self::HOME_ID, $closed['homeId']);
        self::assertSame('closed', $closed['status']);
        self::assertSame(5, $closed['revision']);
        self::assertSame(self::USER_ID, $closed['closedByUserId']);
        self::assertSame('2026-08-11 10:00:00', $closed['closedAt']);
        self::assertCount(1, $closed['lines']);
        self::assertSame(self::LINE_ID, $closed['lines'][0]['id']);
        self::assertSame('0', $closed['lines'][0]['quantity']);
        self::assertSame(0, $this->rowCount('stock_movements'));
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE home_locations (id TEXT, home_id TEXT, name TEXT)',
            'CREATE TABLE stock_count_sessions (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, location_id TEXT NULL,
                status TEXT NOT NULL, notes TEXT NOT NULL, scope_complete INTEGER NOT NULL,
                reliability TEXT NOT NULL, revision INTEGER NOT NULL,
                opened_by_user_id TEXT NOT NULL, opened_at TEXT NOT NULL,
                closed_by_user_id TEXT NULL, closed_at TEXT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE home_products (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, product_id TEXT NULL,
                pack_id TEXT NULL, private_name TEXT NULL, original_pack_text TEXT NULL,
                status TEXT NOT NULL
            )',
            'CREATE TABLE products (id TEXT PRIMARY KEY, canonical_name TEXT NOT NULL)',
            'CREATE TABLE product_packs (id TEXT PRIMARY KEY, original_pack_text TEXT NULL)',
            'CREATE TABLE stock_count_lines (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, session_id TEXT NOT NULL,
                home_product_id TEXT NOT NULL, quantity TEXT NOT NULL,
                confidence TEXT NULL, source TEXT NOT NULL, notes TEXT NOT NULL,
                status TEXT NOT NULL, revision INTEGER NOT NULL,
                counted_by_user_id TEXT NOT NULL, created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE stock_movements (id TEXT PRIMARY KEY)',
            'CREATE TABLE inventory_balances (
                home_id TEXT NOT NULL, home_product_id TEXT NOT NULL,
                quantity TEXT NOT NULL, last_movement_id TEXT NULL,
                revision INTEGER NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE change_log (
                sequence_id INTEGER PRIMARY KEY AUTOINCREMENT, home_id TEXT NOT NULL,
                entity_type TEXT NOT NULL, entity_id TEXT NOT NULL,
                operation_type TEXT NOT NULL, revision INTEGER NOT NULL,
                payload_schema_version INTEGER NOT NULL, payload_json TEXT NOT NULL,
                changed_by_user_id TEXT NOT NULL, changed_at TEXT NOT NULL
            )',
            'CREATE TABLE outbox_messages (
                id TEXT PRIMARY KEY, message_type TEXT NOT NULL, queue_name TEXT NOT NULL,
                payload TEXT NOT NULL, occurred_at TEXT NOT NULL, available_at TEXT NOT NULL,
                published_at TEXT NULL, attempts INTEGER NOT NULL, last_error TEXT NULL,
                status TEXT NOT NULL
            )',
        ];
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(self::USER_ID, 'session', 'device', self::HOME_ID, []);
    }
}

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- focused test adapters belong with the integration.
final readonly class CountCancellationFixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final readonly class CountCancellationTransactionManager implements TransactionManager
{
    public function __construct(private Connection $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional(
            static fn (Connection $_connection): mixed => $operation(),
        );
    }
}
