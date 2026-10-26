<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Administration\Infrastructure\Doctrine\DbalOperatorWorkspaceStore;

final class OperatorProductProjectionTest extends TestCase
{
    private Connection $db;
    private DbalOperatorWorkspaceStore $store;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schemas = [
            'CREATE TABLE home_products (id TEXT PRIMARY KEY, home_id TEXT, product_id TEXT, pack_id TEXT,
                private_name TEXT, original_pack_text TEXT, home_category_id TEXT, global_category_id TEXT,
                unit TEXT DEFAULT \'units\', status TEXT DEFAULT \'active\', revision INTEGER DEFAULT 1)',
            'CREATE TABLE products (id TEXT PRIMARY KEY, canonical_name TEXT, brand TEXT,
                category_id TEXT, status TEXT)',
            'CREATE TABLE product_packs (id TEXT PRIMARY KEY, product_id TEXT, original_pack_text TEXT, status TEXT)',
            'CREATE TABLE categories (id TEXT PRIMARY KEY, canonical_name TEXT, status TEXT)',
            'CREATE TABLE home_categories (id TEXT PRIMARY KEY, home_id TEXT, name TEXT)',
            'CREATE TABLE inventory_balances (home_id TEXT, home_product_id TEXT, quantity TEXT,
                last_movement_id TEXT, revision INTEGER DEFAULT 1, updated_at TEXT,
                uncertain INTEGER DEFAULT 0, pending_quantity TEXT DEFAULT \'0\',
                limitations_json TEXT, checkpoint_id TEXT, PRIMARY KEY (home_id, home_product_id))',
        ];
        foreach ($schemas as $sql) {
            $this->db->executeStatement($sql);
        }
        $this->db->insert('categories', ['id' => 'food', 'canonical_name' => 'Food', 'status' => 'published']);
        $this->db->insert('categories', ['id' => 'dry', 'canonical_name' => 'Dry goods', 'status' => 'published']);
        $this->db->insert('products', [
            'id' => 'rice', 'canonical_name' => 'Basmati rice', 'brand' => 'Test brand',
            'category_id' => 'food', 'status' => 'published',
        ]);
        $this->db->insert('product_packs', [
            'id' => 'bag', 'product_id' => 'rice', 'original_pack_text' => '5 kg bag', 'status' => 'published',
        ]);
        $this->store = new DbalOperatorWorkspaceStore($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testLinkedProductResolvesReadableColumnsWithoutManufacturingPrivateOverrides(): void
    {
        $this->db->insert('home_products', [
            'id' => 'home-rice', 'home_id' => 'home', 'product_id' => 'rice', 'pack_id' => 'bag', 'revision' => 7,
        ]);
        $before = $this->db->fetchAllAssociative('SELECT * FROM home_products');
        $rows = $this->store->records('home', 'products', 0);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('Basmati rice', $row['name']);
        self::assertSame('Test brand', $row['brand']);
        self::assertSame('5 kg bag', $row['pack_text']);
        self::assertSame('Food', $row['category_name']);
        self::assertSame('Global', $row['category_scope']);
        self::assertSame('Catalog linked', $row['catalog_reference']);
        self::assertNull($row['private_name']);
        self::assertNull($row['original_pack_text']);
        self::assertSame('home-rice', $row['id']);
        self::assertSame('bag', $row['pack_id']);
        self::assertSame(7, $row['revision']);
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM home_products'));
    }

    public function testHouseholdOverridesAndLocalCategoriesTakePrecedenceWithoutChangingGlobalData(): void
    {
        $this->db->insert('home_categories', ['id' => 'pantry', 'home_id' => 'home', 'name' => 'My pantry']);
        $this->db->insert('home_products', [
            'id' => 'rice', 'home_id' => 'home', 'product_id' => 'rice', 'pack_id' => 'bag',
            'private_name' => 'Our rice', 'original_pack_text' => 'Bulk sack', 'home_category_id' => 'pantry',
        ]);
        $row = $this->store->records('home', 'products', 0)[0];
        self::assertSame('Our rice', $row['name']);
        self::assertSame('Bulk sack', $row['pack_text']);
        self::assertSame('My pantry', $row['category_name']);
        self::assertSame('Local', $row['category_scope']);
        self::assertSame('Basmati rice', $this->db->fetchOne('SELECT canonical_name FROM products'));
        self::assertSame('5 kg bag', $this->db->fetchOne('SELECT original_pack_text FROM product_packs'));
    }

    public function testPrivateProductCanResolveGlobalCategoryWithoutCatalogProductOrLocalCategory(): void
    {
        $this->db->insert('home_products', [
            'id' => 'private', 'home_id' => 'home', 'private_name' => 'Garden beans',
            'original_pack_text' => 'Harvest basket', 'global_category_id' => 'dry',
        ]);
        $row = $this->store->records('home', 'products', 0)[0];
        self::assertSame('Garden beans', $row['name']);
        self::assertSame('Harvest basket', $row['pack_text']);
        self::assertSame('Dry goods', $row['category_name']);
        self::assertSame('Global', $row['category_scope']);
        self::assertSame('Private product', $row['catalog_reference']);
        self::assertNull($row['product_id']);
    }

    public function testArchivedAndFamilyLinkedProductsKeepReadableNames(): void
    {
        $this->db->update('products', ['status' => 'archived'], ['id' => 'rice']);
        $this->db->insert('home_products', [
            'id' => 'family', 'home_id' => 'home', 'product_id' => 'rice', 'status' => 'archived',
            'private_name' => '   ', 'original_pack_text' => '',
        ]);
        $row = $this->store->records('home', 'products', 0)[0];
        self::assertSame('Basmati rice', $row['name']);
        self::assertSame('Not specified', $row['pack_text']);
        self::assertSame('Catalog product; no pack selected', $row['catalog_reference']);
        self::assertSame('archived', $row['status']);
    }

    public function testBrokenReferencesRemainVisibleWithoutBorrowingAnotherProductPackOrHomeCategory(): void
    {
        $this->db->insert('home_categories', ['id' => 'secret', 'home_id' => 'other', 'name' => 'Other home label']);
        $this->db->insert('home_products', [
            'id' => 'broken', 'home_id' => 'home', 'product_id' => 'missing', 'pack_id' => 'bag',
            'home_category_id' => 'secret',
        ]);
        $row = $this->store->records('home', 'products', 0)[0];
        self::assertSame('Unresolved product', $row['name']);
        self::assertSame('Not specified', $row['pack_text']);
        self::assertSame('Unavailable local category', $row['category_name']);
        self::assertSame('Missing catalog product', $row['catalog_reference']);
        self::assertSame('missing', $row['product_id']);
        $this->db->update('home_products', [
            'product_id' => 'rice', 'pack_id' => 'missing-pack', 'home_category_id' => null,
            'global_category_id' => 'missing-category',
        ], ['id' => 'broken']);
        $row = $this->store->records('home', 'products', 0)[0];
        self::assertSame('Basmati rice', $row['name']);
        self::assertSame('Missing or mismatched catalog pack', $row['catalog_reference']);
        self::assertSame('Unavailable global category', $row['category_name']);
        self::assertSame('Global', $row['category_scope']);
    }

    public function testProductPaginationIsStableHomeBoundAndDoesNotRequireCountedStock(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->db->insert('home_products', [
                'id' => sprintf('item-%03d', $i), 'home_id' => 'home', 'private_name' => 'Private ' . $i,
            ]);
        }
        $this->db->insert('home_products', ['id' => 'foreign', 'home_id' => 'other', 'private_name' => 'Secret']);
        $first = $this->store->records('home', 'products', -1);
        $second = $this->store->records('home', 'products', 100);
        self::assertCount(100, $first);
        self::assertCount(1, $second);
        self::assertSame('item-000', $first[0]['id']);
        self::assertSame('item-100', $second[0]['id']);
        self::assertSame('Private 100', $second[0]['name']);
        self::assertSame('Uncategorized', $second[0]['category_name']);
        self::assertSame('', $second[0]['category_scope']);
        self::assertSame([], $this->store->records('home', 'products', 200));
        self::assertSame([], $this->store->records('empty-home', 'products', 0));
    }

    public function testStockReusesProductMetadataAndPreservesAllRawBalanceEvidence(): void
    {
        $this->db->insert('home_categories', ['id' => 'pantry', 'home_id' => 'home', 'name' => 'My pantry']);
        $this->db->insert('home_products', [
            'id' => 'home-rice', 'home_id' => 'home', 'product_id' => 'rice', 'pack_id' => 'bag',
            'private_name' => 'Our rice', 'original_pack_text' => 'Bulk sack',
            'home_category_id' => 'pantry', 'unit' => 'kg', 'status' => 'archived',
        ]);
        $this->db->insert('inventory_balances', [
            'home_id' => 'home', 'home_product_id' => 'home-rice', 'quantity' => '-2.12500001',
            'last_movement_id' => 'movement', 'revision' => 7, 'updated_at' => '2026-10-25 12:00:00',
            'uncertain' => 1, 'pending_quantity' => '0.00000001',
            'limitations_json' => '["Review chronology."]', 'checkpoint_id' => 'count-line',
        ]);
        $before = $this->db->fetchAssociative('SELECT * FROM inventory_balances');
        self::assertIsArray($before);
        $row = $this->store->records('home', 'stock', 0)[0];
        foreach ($before as $field => $value) {
            self::assertSame($value, $row[$field], $field);
        }
        $product = $this->store->records('home', 'products', 0)[0];
        foreach (['name', 'brand', 'pack_text', 'category_name', 'category_scope', 'catalog_reference'] as $field) {
            self::assertSame($product[$field], $row[$field], $field);
        }
        self::assertSame('Our rice', $row['name']);
        self::assertSame('Test brand', $row['brand']);
        self::assertSame('Bulk sack', $row['pack_text']);
        self::assertSame('My pantry', $row['category_name']);
        self::assertSame('Local', $row['category_scope']);
        self::assertSame('kg', $row['unit']);
        self::assertSame('archived', $row['home_product_status']);
        self::assertSame('published', $row['catalog_product_status']);
        self::assertSame('published', $row['catalog_pack_status']);
        self::assertSame('rice', $row['product_id']);
        self::assertSame('bag', $row['pack_id']);
        self::assertSame($before, $this->db->fetchAssociative('SELECT * FROM inventory_balances'));
        self::assertSame($row, $this->store->records('home', 'stock', 0)[0]);
    }

    public function testStockCategoryPackAndArchivedCatalogFallbacksMatchProducts(): void
    {
        $this->db->update('products', ['status' => 'archived'], ['id' => 'rice']);
        $this->db->update('product_packs', ['status' => 'archived'], ['id' => 'bag']);
        foreach (
            [
            'inherited' => ['product_id' => 'rice', 'pack_id' => 'bag'],
            'global' => ['private_name' => 'Garden beans', 'global_category_id' => 'dry'],
            'private' => ['private_name' => 'My seeds', 'original_pack_text' => 'Handful'],
            ] as $id => $fields
        ) {
            $this->db->insert('home_products', ['id' => $id, 'home_id' => 'home', ...$fields]);
            $this->db->insert('inventory_balances', [
                'home_id' => 'home', 'home_product_id' => $id, 'quantity' => '0',
            ]);
        }
        $rows = array_column($this->store->records('home', 'stock', 0), null, 'home_product_id');
        self::assertSame('Basmati rice', $rows['inherited']['name']);
        self::assertSame('5 kg bag', $rows['inherited']['pack_text']);
        self::assertSame('Food', $rows['inherited']['category_name']);
        self::assertSame('Global', $rows['inherited']['category_scope']);
        self::assertSame('archived', $rows['inherited']['catalog_product_status']);
        self::assertSame('archived', $rows['inherited']['catalog_pack_status']);
        self::assertSame('Dry goods', $rows['global']['category_name']);
        self::assertSame('Global', $rows['global']['category_scope']);
        self::assertSame('Private product', $rows['global']['catalog_reference']);
        self::assertSame('Handful', $rows['private']['pack_text']);
        self::assertSame('Uncategorized', $rows['private']['category_name']);
        self::assertSame('', $rows['private']['category_scope']);
        self::assertSame('', $rows['private']['brand']);
        self::assertSame('0', $rows['private']['quantity']);
    }

    public function testStockBrokenAndForeignReferencesStayVisibleWithoutLeakingPrivateMetadata(): void
    {
        $this->db->insert('home_categories', ['id' => 'secret', 'home_id' => 'other', 'name' => 'Secret category']);
        foreach (
            [
            'foreign' => ['home_id' => 'other', 'private_name' => 'Secret product', 'unit' => 'litres'],
            'mismatch' => ['product_id' => 'missing', 'pack_id' => 'bag', 'home_category_id' => 'secret'],
            'pack-mismatch' => ['pack_id' => 'bag', 'private_name' => 'Private product'],
            ] as $id => $fields
        ) {
            $this->db->insert('home_products', ['id' => $id, 'home_id' => 'home', ...$fields]);
        }
        foreach (['foreign', 'missing', 'mismatch', 'pack-mismatch'] as $id) {
            $this->db->insert('inventory_balances', [
                'home_id' => 'home', 'home_product_id' => $id, 'quantity' => '-0.5',
            ]);
        }
        $rows = array_column($this->store->records('home', 'stock', 0), null, 'home_product_id');
        self::assertCount(4, $rows);
        foreach (['foreign', 'missing'] as $id) {
            self::assertSame('Unresolved product', $rows[$id]['name']);
            self::assertSame('Missing home product', $rows[$id]['catalog_reference']);
            self::assertNull($rows[$id]['unit']);
            self::assertNull($rows[$id]['product_id']);
            self::assertNull($rows[$id]['home_product_status']);
        }
        self::assertSame('Missing catalog product', $rows['mismatch']['catalog_reference']);
        self::assertSame('missing', $rows['mismatch']['product_id']);
        self::assertSame('bag', $rows['mismatch']['pack_id']);
        self::assertSame('Not specified', $rows['mismatch']['pack_text']);
        self::assertSame('Unavailable local category', $rows['mismatch']['category_name']);
        self::assertSame('Local', $rows['mismatch']['category_scope']);
        self::assertSame('secret', $rows['mismatch']['home_category_id']);
        self::assertSame('Not specified', $rows['pack-mismatch']['pack_text']);
        self::assertSame('Missing or mismatched catalog pack', $rows['pack-mismatch']['catalog_reference']);
        self::assertNull($rows['pack-mismatch']['catalog_pack_status']);
    }

    public function testStockPaginationUsesOnlyExistingBalanceRowsAndStableTenantProductOrder(): void
    {
        for ($index = 0; $index < 101; $index++) {
            $this->db->insert('inventory_balances', [
                'home_id' => 'home', 'home_product_id' => sprintf('item-%03d', $index), 'quantity' => '0.25',
            ]);
        }
        $this->db->insert('home_products', ['id' => 'uncounted', 'home_id' => 'home', 'private_name' => 'Uncounted']);
        $this->db->insert('inventory_balances', [
            'home_id' => 'other', 'home_product_id' => 'foreign', 'quantity' => '99',
        ]);
        $first = $this->store->records('home', 'stock', -1);
        $second = $this->store->records('home', 'stock', 100);
        self::assertCount(100, $first);
        self::assertCount(1, $second);
        self::assertSame('item-000', $first[0]['home_product_id']);
        self::assertSame('item-099', $first[99]['home_product_id']);
        self::assertSame('item-100', $second[0]['home_product_id']);
        self::assertSame('0.25', $second[0]['quantity']);
        self::assertSame([], $this->store->records('home', 'stock', 200));
        self::assertSame([], $this->store->records('empty-home', 'stock', 0));
    }

    public function testStockDecimalStringsSurviveSqliteNumericAffinity(): void
    {
        $this->db->executeStatement('DROP TABLE inventory_balances');
        $this->db->executeStatement(
            'CREATE TABLE inventory_balances (home_id TEXT, home_product_id TEXT, quantity DECIMAL(20, 8),
                pending_quantity DECIMAL(20, 8), revision INTEGER DEFAULT 1)',
        );
        foreach (['zero' => '0', 'negative' => '-0.25', 'small' => '0.00000001'] as $id => $quantity) {
            $this->db->insert('inventory_balances', [
                'home_id' => 'home', 'home_product_id' => $id,
                'quantity' => $quantity, 'pending_quantity' => '-0.00000001',
            ]);
        }
        $rows = array_column($this->store->records('home', 'stock', 0), null, 'home_product_id');
        self::assertSame('0', $rows['zero']['quantity']);
        self::assertSame('-0.25', $rows['negative']['quantity']);
        self::assertSame('0.00000001', $rows['small']['quantity']);
        foreach ($rows as $row) {
            self::assertSame('-0.00000001', $row['pending_quantity']);
            self::assertStringContainsString('"quantity":"', json_encode($row, JSON_THROW_ON_ERROR));
        }
    }
}
