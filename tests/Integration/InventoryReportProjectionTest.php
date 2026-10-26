<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;

final class InventoryReportProjectionTest extends TestCase
{
    private Connection $db;
    private DbalInventoryStore $store;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (
            [
            'CREATE TABLE home_products (id TEXT PRIMARY KEY, home_id TEXT, product_id TEXT, pack_id TEXT,
                private_name TEXT, original_pack_text TEXT, unit TEXT, status TEXT)',
            'CREATE TABLE products (id TEXT PRIMARY KEY, canonical_name TEXT)',
            'CREATE TABLE product_packs (id TEXT PRIMARY KEY, original_pack_text TEXT)',
            'CREATE TABLE inventory_balances (home_id TEXT, home_product_id TEXT, quantity TEXT,
                revision INTEGER, last_movement_id TEXT, updated_at TEXT, uncertain INTEGER DEFAULT 0,
                pending_quantity TEXT DEFAULT \'0\', limitations_json TEXT, checkpoint_id TEXT,
                PRIMARY KEY (home_id, home_product_id))',
            'CREATE TABLE stock_threshold_preferences (home_id TEXT, home_product_id TEXT,
                minimum_quantity TEXT, always_keep INTEGER, never_suggest INTEGER)',
            ] as $sql
        ) {
            $this->db->executeStatement($sql);
        }
        $this->store = new DbalInventoryStore($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testUnknownRecordedZeroSignedAndUncertainQuantitiesRemainDistinct(): void
    {
        foreach (['unknown', 'zero', 'negative', 'fractional', 'uncertain'] as $id) {
            $this->product($id);
        }
        foreach (['zero' => '0', 'negative' => '-2.125', 'fractional' => '0.00000001'] as $id => $quantity) {
            $this->balance($id, $quantity);
        }
        $this->balance('uncertain', '-1.75', [
            'uncertain' => 1,
            'pending_quantity' => '2.12500001',
            'limitations_json' => '["A same-day purchase requires chronology review."]',
            'checkpoint_id' => 'count-line',
        ]);
        $rows = array_column($this->store->inventoryReport('home'), null, 'homeProductId');
        self::assertNull($rows['unknown']['factualQuantity']);
        self::assertSame('unknown', $rows['unknown']['quantityState']);
        self::assertNull($rows['unknown']['pendingQuantity']);
        self::assertNull($rows['unknown']['balanceRevision']);
        self::assertNull($rows['unknown']['checkpointId']);
        self::assertNotEmpty($rows['unknown']['limitations']);
        self::assertSame('0', $rows['zero']['factualQuantity']);
        self::assertSame('recorded', $rows['zero']['quantityState']);
        self::assertFalse($rows['zero']['uncertain']);
        self::assertSame('0', $rows['zero']['pendingQuantity']);
        self::assertSame([], $rows['zero']['limitations']);
        self::assertSame('-2.125', $rows['negative']['factualQuantity']);
        self::assertSame('recorded', $rows['negative']['quantityState']);
        self::assertSame('0.00000001', $rows['fractional']['factualQuantity']);
        self::assertSame('uncertain', $rows['uncertain']['quantityState']);
        self::assertTrue($rows['uncertain']['uncertain']);
        self::assertSame('-1.75', $rows['uncertain']['factualQuantity']);
        self::assertSame('2.12500001', $rows['uncertain']['pendingQuantity']);
        self::assertSame('count-line', $rows['uncertain']['checkpointId']);
        self::assertSame(['A same-day purchase requires chronology review.'], $rows['uncertain']['limitations']);
        self::assertSame(7, $rows['uncertain']['balanceRevision']);
        self::assertSame('movement-uncertain', $rows['uncertain']['lastMovementId']);
        self::assertSame('kg', $rows['uncertain']['stockUnit']);
    }

    public function testHomePrivateMetadataPreferencesAndActivePopulationSurviveReadOnlyProjection(): void
    {
        $this->db->insert('products', ['id' => 'catalog', 'canonical_name' => 'Catalog rice']);
        $this->db->insert('product_packs', ['id' => 'pack', 'original_pack_text' => '5 kg bag']);
        $this->product('rice', [
            'product_id' => 'catalog', 'pack_id' => 'pack', 'private_name' => 'Our rice',
            'original_pack_text' => 'Household bulk sack',
        ]);
        $this->product('catalog', ['product_id' => 'catalog', 'pack_id' => 'pack', 'private_name' => null]);
        $this->product('archived', ['status' => 'archived']);
        $this->product('foreign', ['home_id' => 'other']);
        $this->balance('rice', '4.25');
        $this->db->insert('stock_threshold_preferences', [
            'home_id' => 'home', 'home_product_id' => 'rice',
            'minimum_quantity' => '1.5', 'always_keep' => 1, 'never_suggest' => 0,
        ]);
        $this->db->insert('stock_threshold_preferences', [
            'home_id' => 'other', 'home_product_id' => 'catalog',
            'minimum_quantity' => '99', 'always_keep' => 1, 'never_suggest' => 1,
        ]);
        $before = $this->snapshot();
        $first = $this->store->inventoryReport('home');
        self::assertSame($first, $this->store->inventoryReport('home'));
        self::assertSame($before, $this->snapshot());
        $rows = array_column($first, null, 'homeProductId');
        self::assertCount(2, $rows);
        self::assertSame('Our rice', $rows['rice']['productName']);
        self::assertSame('Household bulk sack', $rows['rice']['packText']);
        self::assertSame('1.5', $rows['rice']['configuredMinimum']);
        self::assertTrue($rows['rice']['alwaysKeep']);
        self::assertFalse($rows['rice']['neverSuggest']);
        self::assertSame('Catalog rice', $rows['catalog']['productName']);
        self::assertSame('5 kg bag', $rows['catalog']['packText']);
        self::assertNull($rows['catalog']['configuredMinimum']);
        self::assertFalse($rows['catalog']['alwaysKeep']);
        self::assertFalse($rows['catalog']['neverSuggest']);
        self::assertSame([], $this->store->inventoryReport('empty-home'));
    }

    public function testReportDecimalStringsSurviveSqliteNumericAffinity(): void
    {
        $this->db->executeStatement('DROP TABLE inventory_balances');
        $this->db->executeStatement(
            'CREATE TABLE inventory_balances (home_id TEXT, home_product_id TEXT, quantity DECIMAL(20, 8),
                revision INTEGER, last_movement_id TEXT, updated_at TEXT, uncertain INTEGER DEFAULT 0,
                pending_quantity DECIMAL(20, 8) DEFAULT 0, limitations_json TEXT, checkpoint_id TEXT)',
        );
        foreach (['zero' => '0', 'negative' => '-0.25', 'small' => '0.00000001'] as $id => $quantity) {
            $this->product($id);
            $this->balance($id, $quantity, ['pending_quantity' => '-0.00000001']);
        }
        $rows = array_column($this->store->inventoryReport('home'), null, 'homeProductId');
        self::assertSame('0', $rows['zero']['factualQuantity']);
        self::assertSame('-0.25', $rows['negative']['factualQuantity']);
        self::assertSame('0.00000001', $rows['small']['factualQuantity']);
        foreach ($rows as $row) {
            self::assertSame('-0.00000001', $row['pendingQuantity']);
            self::assertStringContainsString('"factualQuantity":"', json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    /** @param array<string, mixed> $overrides */
    private function product(string $id, array $overrides = []): void
    {
        $this->db->insert('home_products', array_replace([
            'id' => $id, 'home_id' => 'home', 'private_name' => $id,
            'unit' => 'kg', 'status' => 'active',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function balance(string $id, string $quantity, array $overrides = []): void
    {
        $this->db->insert('inventory_balances', array_replace([
            'home_id' => 'home', 'home_product_id' => $id, 'quantity' => $quantity,
            'revision' => 7, 'last_movement_id' => 'movement-' . $id, 'updated_at' => '2026-10-25 12:00:00',
        ], $overrides));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['home_products', 'inventory_balances', 'stock_threshold_preferences'] as $table) {
            $snapshot[$table] = $this->db->fetchAllAssociative('SELECT * FROM ' . $table);
        }
        return $snapshot;
    }
}
