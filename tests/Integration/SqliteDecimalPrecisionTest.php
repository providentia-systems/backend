<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;
use Providentia\Migrations\Version20261005000100;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Purchasing\Infrastructure\Doctrine\DbalPurchasingStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Shopping\Application\ShoppingService;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;
use Providentia\Shopping\Infrastructure\Doctrine\DbalShoppingStore;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalChangeFeedWriter;
use Psr\Log\NullLogger;

final class SqliteDecimalPrecisionTest extends TestCase
{
    private const PREVIOUS = 'Providentia\\Migrations\\Version20260922000100';
    private const CURRENT = 'Providentia\\Migrations\\Version20261005000100';
    private const HOME = '01912345-6789-7abc-8def-0123456789ab';
    private const PRODUCT = '01912345-6789-7abc-9def-0123456789ab';
    private const USER = '01912345-6789-7abc-adef-0123456789ab';
    private const AT = '2026-10-05 12:00:00';

    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrate(self::PREVIOUS);
        $this->db->insert('homes', [
            'id' => self::HOME, 'name' => 'Decimal test', 'default_locale' => 'en-NA',
            'default_currency' => 'NAD', 'default_timezone' => 'UTC', 'status' => 'active',
            'created_at' => self::AT, 'updated_at' => self::AT,
        ]);
        $this->db->insert('users', [
            'id' => self::USER, 'email' => 'decimal@example.test', 'normalized_email' => 'decimal@example.test',
            'status' => 'active',
            'created_at' => self::AT, 'updated_at' => self::AT,
        ]);
        (new DbalInventoryStore($this->db))->createHomeProduct(
            self::PRODUCT,
            self::HOME,
            null,
            null,
            'Decimal item',
            'decimal item',
            null,
            null,
            new DateTimeImmutable(self::AT),
        );
    }

    public function testUpgradePreservesDataIndexesForeignKeysDefaultsAndExistingTinyDecimals(): void
    {
        $this->seedReceipt('0.00000001');
        $this->seedLegacyConfidence();
        $this->db->executeStatement(
            "ALTER TABLE units ADD COLUMN precision_marker TEXT NOT NULL DEFAULT 'kept' CHECK (precision_marker <> '')",
        );
        $this->db->insert('units', [
            'id' => 'unit', 'symbol' => 'u', 'name' => 'Unit', 'dimension' => 'count',
            'base_factor' => '12345678.12345678', 'status' => 'published', 'revision' => 1,
            'created_at' => self::AT, 'updated_at' => self::AT,
        ]);
        $this->db->executeStatement(
            'CREATE TRIGGER decimal_receipt_audit AFTER UPDATE OF notes ON receipts '
                . "BEGIN INSERT INTO foundation_records (id, label, created_at) VALUES (NEW.id, NEW.notes, '"
                . self::AT . "'); END",
        );
        $before = $this->definitions();
        $columns = $this->db->fetchAllAssociative('PRAGMA table_info(receipt_lines)');
        $keys = $this->db->fetchAllAssociative('PRAGMA foreign_key_list(receipt_lines)');
        $this->migrate(self::CURRENT);
        $after = $this->definitions();
        foreach ($before as $key => $sql) {
            self::assertSame(
                preg_replace('/(?:NUMERIC|DECIMAL)\(\d+,\s*\d+\)/i', 'TEXT', $sql),
                $after[$key],
                $key,
            );
        }
        foreach ($columns as &$column) {
            if (preg_match('/^(?:NUMERIC|DECIMAL)/i', (string) $column['type']) === 1) {
                $column['type'] = 'TEXT';
            }
        }
        unset($column);
        self::assertSame($columns, $this->db->fetchAllAssociative('PRAGMA table_info(receipt_lines)'));
        self::assertSame($keys, $this->db->fetchAllAssociative('PRAGMA foreign_key_list(receipt_lines)'));
        self::assertSame([], $this->db->fetchAllAssociative('PRAGMA foreign_key_check'));
        self::assertSame('0.00000001', $this->db->fetchOne('SELECT quantity FROM receipt_lines'));
        self::assertSame('medium', $this->db->fetchOne('SELECT confidence FROM shopping_list_lines'));
        self::assertSame('12.5', $this->db->fetchOne('SELECT line_total FROM receipt_lines'));
        self::assertSame('12345678.12345678', $this->db->fetchOne('SELECT base_factor FROM units'));
        self::assertSame('kept', $this->db->fetchOne('SELECT precision_marker FROM units'));
        self::assertNull($this->db->fetchOne('SELECT unit_price FROM receipt_lines'));
        self::assertSame('text', $this->db->fetchOne('SELECT typeof(quantity) FROM receipt_lines'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM foundation_records'));
        $this->db->executeStatement("UPDATE receipts SET notes = 'Preserved trigger'");
        self::assertSame('Preserved trigger', $this->db->fetchOne('SELECT label FROM foundation_records'));
        $this->db->executeStatement('PRAGMA foreign_keys = ON');
        try {
            $this->db->executeStatement("UPDATE receipt_lines SET receipt_id = 'missing'");
            self::fail('The receipt foreign key was lost.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertSame('receipt', $this->db->fetchOne('SELECT receipt_id FROM receipt_lines'));
        }
        try {
            $this->db->executeStatement(
                'INSERT INTO receipt_lines SELECT \'duplicate\', home_id, receipt_id, line_number, raw_description, '
                    . 'quantity, original_pack_text, unit_price, line_total, home_product_id, approval_status, '
                    . 'revision, created_at, updated_at FROM receipt_lines',
            );
            self::fail('The receipt-line unique index was lost.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM receipt_lines'));
        }
        $this->db->delete('receipts', ['id' => 'receipt']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM receipt_lines'));
    }

    public function testUpgradeRefusesForeignKeyCascadesBeforeChangingAnything(): void
    {
        $this->seedReceipt('1.125');
        $before = $this->definitions();
        $this->db->executeStatement('PRAGMA foreign_keys = ON');
        try {
            $this->migrate(self::CURRENT);
            self::fail('An enabled foreign-key cascade must not be allowed during the table rebuild.');
        } catch (AbortMigration $error) {
            self::assertStringContainsString('foreign_keys=OFF', $error->getMessage());
        }
        self::assertSame($before, $this->definitions());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM receipt_lines'));
    }

    public function testSafeDowngradeAndReapplyPreserveSampleValues(): void
    {
        $this->seedReceipt('0.00000001');
        $this->seedLegacyConfidence();
        $this->migrate(self::CURRENT);
        $this->migrate(self::PREVIOUS);
        self::assertSame('real', $this->db->fetchOne('SELECT typeof(quantity) FROM receipt_lines'));
        self::assertSame('medium', $this->db->fetchOne('SELECT confidence FROM shopping_list_lines'));
        $this->migrate(self::CURRENT);
        self::assertSame('0.00000001', $this->db->fetchOne('SELECT quantity FROM receipt_lines'));
        self::assertSame('12.5', $this->db->fetchOne('SELECT line_total FROM receipt_lines'));
    }

    public function testDowngradeRefusesPrecisionLossBeforeChangingAnything(): void
    {
        $this->migrate(self::CURRENT);
        $this->seedReceipt('999999999.99999999');
        $before = $this->definitions();
        try {
            $this->migrate(self::PREVIOUS);
            self::fail('The downgrade must refuse a value SQLite NUMERIC would round.');
        } catch (AbortMigration $error) {
            self::assertStringContainsString(
                'lossy SQLite decimal downgrade: receipt_lines.quantity',
                $error->getMessage(),
            );
        }
        self::assertSame($before, $this->definitions());
        self::assertSame('999999999.99999999', $this->db->fetchOne('SELECT quantity FROM receipt_lines'));
        self::assertSame(1, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?',
            [self::CURRENT],
        ));
    }

    /** @return iterable<string, array{string, string}> */
    public static function quantities(): iterable
    {
        yield 'smallest quantity' => ['0.00000001', '0.00000000'];
        yield 'largest quantity' => ['999999999.99999999', '999999999.99999998'];
    }

    #[DataProvider('quantities')]
    public function testReceiptShoppingLedgerAndReplayKeepEveryDigit(string $quantity, string $afterRemoval): void
    {
        $this->migrate(self::CURRENT);
        $this->db->executeStatement('PRAGMA foreign_keys = ON');
        $at = new DateTimeImmutable(self::AT);
        $ids = new SequenceUuidGenerator();
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($at);
        $authorization = $this->createStub(HomePermissionAuthorizer::class);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            fn (callable $operation): mixed => $this->db->transactional(static fn (): mixed => $operation()),
        );
        $changes = new DbalChangeFeedWriter($this->db, $ids);
        $inventoryStore = new DbalInventoryStore($this->db);
        $inventory = new InventoryService(
            $inventoryStore,
            $authorization,
            $ids,
            $clock,
            $transactions,
            $changes,
            new AccessService($this->createStub(AccessStore::class), $transactions, $ids),
        );
        $purchases = new PurchasingService(
            new DbalPurchasingStore($this->db),
            $inventory,
            $authorization,
            $ids,
            $clock,
            $transactions,
            $changes,
        );
        $identity = new AuthenticatedIdentity(self::USER, 'session', 'device', self::HOME, []);
        $receipt = $purchases->createReceipt(
            $identity,
            self::HOME,
            null,
            '2026-10-05',
            'NAD',
            '12.50',
            '',
            null,
        );
        $receiptId = (string) $receipt['id'];
        $line = $purchases->addLine(
            $identity,
            self::HOME,
            $receiptId,
            1,
            'Exact quantity',
            $quantity,
            null,
            '12.50',
            '12.50',
        );
        self::assertSame($quantity, $line['quantity']);
        $purchases->approveLine($identity, self::HOME, $receiptId, (string) $line['id'], self::PRODUCT, 1);
        $committed = $purchases->commit($identity, self::HOME, $receiptId, 3);
        self::assertSame($quantity, $committed['lines'][0]['quantity']);
        $balance = $inventoryStore->balance(self::HOME, self::PRODUCT);
        self::assertNotNull($balance);
        self::assertSame($quantity, $balance['quantity']);
        self::assertSame($quantity, $this->db->fetchOne('SELECT quantity FROM price_observations'));
        self::assertSame($quantity, $this->db->fetchOne('SELECT quantity_delta FROM stock_movements'));
        $replayed = $purchases->commit($identity, self::HOME, $receiptId, 3);
        self::assertSame(0, $replayed['movements']);
        self::assertSame($quantity, $replayed['lines'][0]['quantity']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM stock_movements'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM price_observations'));

        $shopping = new ShoppingService(
            new DbalShoppingStore($this->db),
            $authorization,
            new LegacySuggestionPolicy(),
            $ids,
            $clock,
            $transactions,
            $changes,
        );
        $list = $shopping->createList($identity, self::HOME, 'Exact list', 'manual');
        $shoppingLine = $shopping->addLine(
            $identity,
            self::HOME,
            (string) $list['id'],
            1,
            self::PRODUCT,
            'Exact quantity',
            $quantity,
        );
        self::assertSame($quantity, $shoppingLine['quantityToBuy']);
        self::assertSame($quantity, $shopping->shoppingList(
            $identity,
            self::HOME,
            (string) $list['id'],
        )['lines'][0]['quantityToBuy']);
        $payloads = $this->db->fetchFirstColumn(
            "SELECT payload_json FROM change_log "
                . "WHERE entity_type IN ('purchasing-receipt-line', 'shopping-list-line')",
        );
        foreach ($payloads as $json) {
            $payload = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertSame($quantity, $payload['quantity'] ?? $payload['quantityToBuy']);
        }
        // Appending to an existing balance must avoid SQLite's REAL arithmetic.
        $remove = fn (): array => $inventoryStore->appendMovement(
            $ids->generate(),
            self::HOME,
            self::PRODUCT,
            'consume',
            '-0.00000001',
            'precision-test',
            'remove',
            '',
            self::USER,
            $at,
            $at,
        );
        $removed = $this->db->transactional($remove);
        self::assertSame($afterRemoval, $removed['balance']);
        self::assertTrue($this->db->transactional($remove)['replayed']);
        self::assertSame($afterRemoval, $inventory->rebuild($identity, self::HOME)['quantity']);
        $balance = $inventoryStore->balance(self::HOME, self::PRODUCT);
        self::assertNotNull($balance);
        self::assertSame($afterRemoval, $balance['quantity']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM stock_movements'));
        self::assertSame([], $this->db->fetchAllAssociative('PRAGMA foreign_key_check'));
    }

    public function testHistoricalNumericRoundingCannotBeRecovered(): void
    {
        $this->seedReceipt('999999999.99999999');
        self::assertSame(1000000000, $this->db->fetchOne('SELECT quantity FROM receipt_lines'));
        $this->migrate(self::CURRENT);
        self::assertSame('1000000000', $this->db->fetchOne('SELECT quantity FROM receipt_lines'));
    }

    /** @return iterable<string, array{AbstractPlatform}> */
    public static function serverPlatforms(): iterable
    {
        yield 'MySQL' => [new MySQLPlatform()];
        yield 'MariaDB' => [new MariaDBPlatform()];
    }

    #[DataProvider('serverPlatforms')]
    public function testServerDecimalSchemaIsUnchanged(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->db->createSchemaManager());
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('executeStatement');
        $migration = new Version20261005000100($connection, new NullLogger());
        $schema = new Schema();
        $schema->createTable('receipt_lines')->addColumn('quantity', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $before = $schema->toSql($platform);
        $migration->up($schema);
        $migration->postUp($schema);
        $migration->down($schema);
        $migration->postDown($schema);
        self::assertSame($before, $schema->toSql($platform));
        self::assertSame([], $migration->getSql());
    }

    private function seedLegacyConfidence(): void
    {
        $store = new DbalShoppingStore($this->db);
        $at = new DateTimeImmutable(self::AT);
        $store->createList('legacy-list', self::HOME, 'Legacy suggestions', 'suggested', self::USER, $at);
        self::assertTrue($store->addLine(
            'legacy-line',
            self::HOME,
            'legacy-list',
            1,
            null,
            'Suggested item',
            'suggested',
            '1.125',
            '',
            'medium',
            $at,
        ));
    }

    private function seedReceipt(string $quantity): void
    {
        $store = new DbalPurchasingStore($this->db);
        $at = new DateTimeImmutable(self::AT);
        $store->createReceipt(
            'receipt',
            self::HOME,
            null,
            '2026-10-05',
            'NAD',
            '12.50',
            'manual',
            null,
            '',
            self::USER,
            $at,
        );
        self::assertTrue($store->addReceiptLine(
            'line',
            self::HOME,
            'receipt',
            1,
            1,
            'Quantity',
            $quantity,
            null,
            null,
            '12.50',
            $at,
        ));
    }

    /** @return array<string, string> */
    private function definitions(): array
    {
        $definitions = [];
        foreach (
            $this->db->fetchAllAssociative(
                "SELECT type, name, sql FROM sqlite_schema "
                    . "WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY name",
            ) as $row
        ) {
            $definitions[(string) $row['type'] . ':' . (string) $row['name']] = (string) $row['sql'];
        }
        return $definitions;
    }

    private function migrate(string $version): void
    {
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray(require dirname(__DIR__, 2) . '/config/migrations.php'),
            new ExistingConnection($this->db),
        );
        $migrations->getMetadataStorage()->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(new Version($version));
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
    }
}
