<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Synchronization\Application\SyncBackfillService;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalChangeFeedWriter;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncBackfillStore;

final class SynchronizationBackfillTest extends TestCase
{
    private const HOME_ONE = '01912345-6789-7abc-8def-0123456789ab';
    private const HOME_TWO = '01912345-6789-7abc-9def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const LOCATION_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const LIST_ID = '01912345-6789-7abc-8def-1123456789ab';
    private const LINE_ID = '01912345-6789-7abc-9def-1123456789ab';
    private const CATEGORY_ID = '01912345-6789-7abc-adef-1123456789ab';
    private const PRODUCT_ID = '01912345-6789-7abc-bdef-1123456789ab';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ($this->schema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        foreach ([self::HOME_ONE, self::HOME_TWO] as $homeId) {
            $this->connection->insert('home_memberships', [
                'home_id' => $homeId,
                'user_id' => self::USER_ID,
                'role' => 'owner',
                'status' => 'active',
            ]);
        }
    }

    public function testBackfillIsHomeScopedBoundedAndIdempotentWithoutDomainMutation(): void
    {
        $at = '2026-08-04 12:00:00';
        $this->connection->insert('home_locations', [
            'id' => self::LOCATION_ID,
            'home_id' => self::HOME_ONE,
            'name' => 'Pantry',
            'kind' => 'pantry',
            'status' => 'active',
            'revision' => 2,
            'updated_at' => $at,
        ]);
        $this->connection->insert('home_locations', [
            'id' => '01912345-6789-7abc-adef-1123456789ab',
            'home_id' => self::HOME_TWO,
            'name' => 'Other home',
            'kind' => 'pantry',
            'status' => 'active',
            'revision' => 1,
            'updated_at' => $at,
        ]);
        $this->connection->insert('shopping_lists', [
            'id' => self::LIST_ID,
            'home_id' => self::HOME_ONE,
            'name' => 'Weekly',
            'kind' => 'manual',
            'status' => 'open',
            'revision' => 2,
            'created_by_user_id' => self::USER_ID,
            'updated_at' => $at,
        ]);
        $this->connection->insert('shopping_list_lines', [
            'id' => self::LINE_ID,
            'home_id' => self::HOME_ONE,
            'shopping_list_id' => self::LIST_ID,
            'home_product_id' => null,
            'description' => 'Milk',
            'source' => 'manual',
            'quantity_to_buy' => '2.00000000',
            'explanation' => 'Added manually.',
            'confidence' => null,
            'checked_at' => null,
            'revision' => 1,
            'updated_at' => $at,
        ]);
        $service = new SyncBackfillService(
            new DbalSyncBackfillStore($this->connection),
            new DbalChangeFeedWriter($this->connection, new SequenceUuidGenerator()),
            new BackfillDbalTransactionManager($this->connection),
        );

        $first = $service->run(self::HOME_ONE, 1);
        $second = $service->run(self::HOME_ONE, 10);
        $replay = $service->run(self::HOME_ONE, 10);

        self::assertSame(['scanned' => 1, 'appended' => 1, 'hasMore' => true, 'byType' => [
            'inventory-location' => 1,
        ]], $first);
        self::assertSame(2, $second['appended']);
        self::assertFalse($second['hasMore']);
        self::assertSame(0, $replay['appended']);
        self::assertSame(3, $this->tableCount('change_log', self::HOME_ONE));
        self::assertSame(0, $this->tableCount('change_log', self::HOME_TWO));
        self::assertSame(2, $this->tableCount('home_locations'));
        self::assertSame(1, $this->tableCount('shopping_lists'));
        self::assertSame(1, $this->tableCount('shopping_list_lines'));
        self::assertSame(self::USER_ID, $this->connection->fetchOne(
            "SELECT changed_by_user_id FROM change_log
             WHERE home_id = :home AND entity_type = 'inventory-location'",
            ['home' => self::HOME_ONE],
        ));
        $line = $this->connection->fetchAssociative(
            "SELECT payload_json FROM change_log
             WHERE home_id = :home AND entity_type = 'shopping-list-line'",
            ['home' => self::HOME_ONE],
        );
        if ($line === false) {
            self::fail('The shopping-list-line feed record is missing.');
        }
        $payload = json_decode((string) $line['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            self::fail('The shopping-list-line feed representation is invalid.');
        }
        self::assertSame('2.00000000', $payload['quantityToBuy']);
        self::assertFalse($payload['checked']);
    }

    public function testPrivateCategoryPrecedesTheProductThatReferencesIt(): void
    {
        $at = '2026-08-24 12:00:00';
        $this->connection->insert('home_categories', [
            'id' => self::CATEGORY_ID,
            'home_id' => self::HOME_ONE,
            'name' => 'Dry goods',
            'status' => 'active',
            'revision' => 1,
            'updated_at' => $at,
        ]);
        $this->connection->insert('home_products', [
            'id' => self::PRODUCT_ID,
            'home_id' => self::HOME_ONE,
            'product_id' => null,
            'pack_id' => null,
            'private_name' => 'Sorghum',
            'original_pack_text' => '1 kg',
            'home_category_id' => self::CATEGORY_ID,
            'status' => 'active',
            'revision' => 1,
            'updated_at' => $at,
        ]);

        $result = (new SyncBackfillService(
            new DbalSyncBackfillStore($this->connection),
            new DbalChangeFeedWriter($this->connection, new SequenceUuidGenerator()),
            new BackfillDbalTransactionManager($this->connection),
        ))->run(self::HOME_ONE, 10);

        self::assertSame(2, $result['appended']);
        $changes = $this->connection->fetchAllAssociative(
            'SELECT entity_type, payload_json FROM change_log
             WHERE home_id = :home ORDER BY sequence_id',
            ['home' => self::HOME_ONE],
        );
        self::assertSame(
            ['inventory-home-category', 'inventory-home-product'],
            array_column($changes, 'entity_type'),
        );
        $product = json_decode((string) $changes[1]['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(self::CATEGORY_ID, $product['homeCategoryId']);
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE home_memberships (home_id TEXT, user_id TEXT, role TEXT, status TEXT)',
            'CREATE TABLE change_log (
                sequence_id INTEGER PRIMARY KEY AUTOINCREMENT, home_id TEXT, entity_type TEXT,
                entity_id TEXT, operation_type TEXT, revision INTEGER, payload_schema_version INTEGER,
                payload_json TEXT, changed_by_user_id TEXT, changed_at TEXT
            )',
            'CREATE TABLE outbox_messages (
                id TEXT PRIMARY KEY, message_type TEXT, queue_name TEXT, payload TEXT,
                occurred_at TEXT, available_at TEXT, published_at TEXT, attempts INTEGER,
                last_error TEXT, status TEXT
            )',
            'CREATE TABLE stock_movements (id TEXT PRIMARY KEY, actor_user_id TEXT)',
            'CREATE TABLE inventory_balances (
                home_id TEXT, home_product_id TEXT, quantity TEXT, last_movement_id TEXT,
                revision INTEGER, updated_at TEXT
            )',
            'CREATE TABLE stock_count_lines (
                id TEXT, home_id TEXT, session_id TEXT, home_product_id TEXT, quantity TEXT,
                confidence TEXT, source TEXT, notes TEXT, status TEXT, revision INTEGER,
                counted_by_user_id TEXT, updated_at TEXT
            )',
            'CREATE TABLE stock_count_sessions (
                id TEXT, home_id TEXT, location_id TEXT, notes TEXT, scope_complete INTEGER,
                reliability TEXT, status TEXT, revision INTEGER, opened_by_user_id TEXT,
                closed_by_user_id TEXT, updated_at TEXT
            )',
            'CREATE TABLE home_categories (
                id TEXT, home_id TEXT, name TEXT, status TEXT, revision INTEGER, updated_at TEXT
            )',
            'CREATE TABLE home_products (
                id TEXT, home_id TEXT, product_id TEXT, pack_id TEXT, private_name TEXT,
                original_pack_text TEXT, home_category_id TEXT, status TEXT,
                revision INTEGER, updated_at TEXT
            )',
            'CREATE TABLE home_locations (
                id TEXT, home_id TEXT, name TEXT, kind TEXT, status TEXT,
                revision INTEGER, updated_at TEXT
            )',
            'CREATE TABLE receipts (
                id TEXT, home_id TEXT, store_id TEXT, purchase_date TEXT, currency TEXT,
                total_amount TEXT, status TEXT, source TEXT, source_reference TEXT, notes TEXT,
                revision INTEGER, created_by_user_id TEXT, updated_at TEXT
            )',
            'CREATE TABLE receipt_lines (
                id TEXT, home_id TEXT, receipt_id TEXT, raw_description TEXT, quantity TEXT,
                original_pack_text TEXT, unit_price TEXT, line_total TEXT, home_product_id TEXT,
                approval_status TEXT, revision INTEGER, updated_at TEXT
            )',
            'CREATE TABLE stores (
                id TEXT, home_id TEXT, name TEXT, location TEXT, status TEXT,
                revision INTEGER, updated_at TEXT
            )',
            'CREATE TABLE shopping_lists (
                id TEXT, home_id TEXT, name TEXT, kind TEXT, status TEXT, revision INTEGER,
                created_by_user_id TEXT, updated_at TEXT
            )',
            'CREATE TABLE shopping_list_lines (
                id TEXT, home_id TEXT, shopping_list_id TEXT, home_product_id TEXT,
                description TEXT, source TEXT, quantity_to_buy TEXT, explanation TEXT,
                confidence TEXT, checked_at TEXT, revision INTEGER, updated_at TEXT
            )',
        ];
    }

    private function tableCount(string $table, ?string $homeId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $table;
        if ($homeId === null) {
            return (int) $this->connection->fetchOne($sql);
        }

        return (int) $this->connection->fetchOne($sql . ' WHERE home_id = :home', ['home' => $homeId]);
    }
}

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- focused test adapter belongs with this integration.
final readonly class BackfillDbalTransactionManager implements TransactionManager
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
