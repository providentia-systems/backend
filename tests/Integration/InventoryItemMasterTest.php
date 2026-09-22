<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;

final class InventoryItemMasterTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const OTHER_HOME_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const CATEGORY_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const OTHER_CATEGORY_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const BEANS_ID = '01912345-6789-7abc-cdef-0123456789ab';
    private const RICE_ID = '01912345-6789-7abc-ddef-0123456789ab';
    private const BEANS_PACK_ONE = '01912345-6789-7abc-edef-0123456789ab';
    private const BEANS_PACK_TWO = '01912345-6789-7abc-8def-1123456789ab';
    private const RICE_PACK = '01912345-6789-7abc-9def-1123456789ab';
    private const HOME_PRODUCT_ID = '01912345-6789-7abc-adef-1123456789ab';
    private const OTHER_HOME_PRODUCT_ID = '01912345-6789-7abc-bdef-2123456789ab';
    private const HOME_CATEGORY_ID = '01912345-6789-7abc-cdef-2123456789ab';
    private const PRIVATE_PRODUCT_ID = '01912345-6789-7abc-ddef-2123456789ab';

    private Connection $connection;
    private DbalInventoryStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ($this->schema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->seedCatalog();
        $this->store = new DbalInventoryStore($this->connection);
    }


    public function testLinkedHouseholdOverridesPreserveCatalogIdentityAndBalances(): void
    {
        $beforeCatalog = $this->connection->fetchAllAssociative('SELECT * FROM products ORDER BY id');
        $beforePacks = $this->connection->fetchAllAssociative('SELECT * FROM product_packs ORDER BY id');
        $beforeBalances = $this->connection->fetchAllAssociative('SELECT * FROM inventory_balances ORDER BY home_id');
        $at = new DateTimeImmutable('2026-09-22T12:00:00Z');
        $result = $this->store->updateHomeProduct(
            self::HOME_ID,
            self::HOME_PRODUCT_ID,
            true,
            'My beans',
            'my beans',
            true,
            'Home jar',
            false,
            null,
            null,
            1,
            $at,
            true,
            self::OTHER_CATEGORY_ID,
            'kg',
        );
        self::assertSame('updated', $result['status']);
        self::assertSame(2, $result['record']['revision']);
        self::assertSame(self::BEANS_ID, $result['record']['productId']);
        self::assertSame(self::BEANS_PACK_ONE, $result['record']['packId']);
        self::assertSame('kg', $result['record']['unit']);
        $reopened = new DbalInventoryStore($this->connection);
        $page = $reopened->itemMaster(self::HOME_ID, 'my beans', self::OTHER_CATEGORY_ID, null, 100, 0);
        self::assertSame(1, $page['total']);
        $item = $page['items'][0];
        self::assertSame('My beans', $item['canonicalName']);
        self::assertSame('Baked Beans', $item['catalogName']);
        self::assertSame('Home jar', $item['packText']);
        self::assertSame('1 kg', $item['catalogPackText']);
        self::assertSame(self::OTHER_CATEGORY_ID, $item['categoryId']);
        self::assertSame(self::CATEGORY_ID, $item['catalogCategoryId']);
        self::assertSame('kg', $item['unit']);
        self::assertSame('3.50000000', $item['quantity']);
        self::assertSame($beforeCatalog, $this->connection->fetchAllAssociative('SELECT * FROM products ORDER BY id'));
        self::assertSame($beforePacks, $this->connection->fetchAllAssociative('SELECT * FROM product_packs ORDER BY id'));
        self::assertSame($beforeBalances, $this->connection->fetchAllAssociative('SELECT * FROM inventory_balances ORDER BY home_id'));
        self::assertSame(0, $this->store->itemMaster(self::OTHER_HOME_ID, 'my beans', null, null, 100, 0)['total']);
        $stale = $this->store->updateHomeProduct(
            self::HOME_ID,
            self::HOME_PRODUCT_ID,
            true,
            'Stale edit',
            'stale edit',
            false,
            null,
            false,
            null,
            null,
            1,
            $at,
        );
        self::assertSame('revision-conflict', $stale['status']);
        $reset = $this->store->updateHomeProduct(
            self::HOME_ID,
            self::HOME_PRODUCT_ID,
            true,
            null,
            null,
            true,
            null,
            true,
            null,
            null,
            2,
            $at,
            true,
            null,
        );
        self::assertSame('updated', $reset['status']);
        $inherited = $reopened->itemMaster(self::HOME_ID, 'baked', self::CATEGORY_ID, null, 100, 0)['items'];
        $row = array_values(array_filter($inherited, static fn (array $row): bool =>
            $row['homeProductId'] === self::HOME_PRODUCT_ID))[0];
        self::assertSame('Baked Beans', $row['canonicalName']);
        self::assertSame('1 kg', $row['packText']);
        self::assertNull($row['globalCategoryId']);
        self::assertSame('kg', $row['unit']);
    }

    public function testPrivateProductUsesGlobalCategoryWithoutAnyLocalCategories(): void
    {
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM home_categories'));
        $at = new DateTimeImmutable('2026-09-22T12:00:00Z');
        $this->store->createHomeProduct(
            self::PRIVATE_PRODUCT_ID,
            self::HOME_ID,
            null,
            null,
            'Apples',
            'apples',
            'Loose',
            null,
            $at,
            self::OTHER_CATEGORY_ID,
            'kg',
        );
        $page = $this->store->itemMaster(self::HOME_ID, 'apples', self::OTHER_CATEGORY_ID, null, 100, 0);
        self::assertSame(1, $page['total']);
        $item = $page['items'][0];
        self::assertNull($item['productId']);
        self::assertNull($item['catalogName']);
        self::assertNull($item['catalogCategoryId']);
        self::assertSame(self::OTHER_CATEGORY_ID, $item['categoryId']);
        self::assertSame('global', $item['categorySource']);
        self::assertSame('kg', $item['unit']);
        $this->insertPrivateCategory();
        $local = $this->store->updateHomeProduct(
            self::HOME_ID,
            self::PRIVATE_PRODUCT_ID,
            true,
            'Green apples',
            'green apples',
            false,
            null,
            true,
            self::HOME_CATEGORY_ID,
            null,
            1,
            $at,
        );
        self::assertSame('updated', $local['status']);
        self::assertNull($local['record']['globalCategoryId']);
        self::assertSame(self::HOME_CATEGORY_ID, $local['record']['homeCategoryId']);
        self::assertSame('kg', $local['record']['unit']);
        $global = $this->store->updateHomeProduct(
            self::HOME_ID,
            self::PRIVATE_PRODUCT_ID,
            false,
            null,
            null,
            false,
            null,
            false,
            null,
            null,
            2,
            $at,
            true,
            self::CATEGORY_ID,
        );
        self::assertSame('updated', $global['status']);
        self::assertNull($global['record']['homeCategoryId']);
        self::assertSame(self::CATEGORY_ID, $global['record']['globalCategoryId']);
        $nameless = $this->store->updateHomeProduct(
            self::HOME_ID,
            self::PRIVATE_PRODUCT_ID,
            true,
            null,
            null,
            false,
            null,
            false,
            null,
            null,
            3,
            $at,
        );
        self::assertSame('name-required', $nameless['status']);
    }

    public function testGlobalCategorySelectionRejectsUnpublishedAndMixedScopes(): void
    {
        $at = new DateTimeImmutable('2026-09-22T12:00:00Z');
        $this->connection->update('categories', ['status' => 'draft'], ['id' => self::OTHER_CATEGORY_ID]);
        $denied = $this->store->updateHomeProduct(
            self::HOME_ID,
            self::HOME_PRODUCT_ID,
            false,
            null,
            null,
            false,
            null,
            false,
            null,
            null,
            1,
            $at,
            true,
            self::OTHER_CATEGORY_ID,
        );
        self::assertSame('category-unavailable', $denied['status']);
        self::assertSame(1, (int) $this->store->homeProduct(self::HOME_ID, self::HOME_PRODUCT_ID)['revision']);
        $this->insertPrivateCategory();
        $mixed = $this->store->updateHomeProduct(
            self::HOME_ID,
            self::HOME_PRODUCT_ID,
            false,
            null,
            null,
            false,
            null,
            true,
            self::HOME_CATEGORY_ID,
            null,
            1,
            $at,
            true,
            self::CATEGORY_ID,
        );
        self::assertSame('category-conflict', $mixed['status']);
        $foreign = $this->store->updateHomeProduct(
            self::OTHER_HOME_ID,
            self::HOME_PRODUCT_ID,
            true,
            'Unauthorized',
            'unauthorized',
            false,
            null,
            false,
            null,
            null,
            1,
            $at,
            true,
            self::CATEGORY_ID,
        );
        self::assertSame('not-found', $foreign['status']);
        $this->expectException(\DomainException::class);
        $this->store->createHomeProduct(
            self::PRIVATE_PRODUCT_ID,
            self::HOME_ID,
            null,
            null,
            'Apples',
            'apples',
            null,
            null,
            $at,
            self::OTHER_CATEGORY_ID,
        );
    }

    public function testProductFamilyWithoutResolvedPackRemainsVisibleWithoutGuessingAPack(): void
    {
        $this->store->createHomeProduct(
            self::PRIVATE_PRODUCT_ID,
            self::HOME_ID,
            self::BEANS_ID,
            null,
            null,
            null,
            'original unresolved pack wording',
            null,
            new DateTimeImmutable('2026-09-14T12:00:00+00:00'),
        );
        $reopened = new DbalInventoryStore($this->connection);
        $page = $reopened->itemMaster(self::HOME_ID, '', null, null, 100, 0);
        self::assertSame(4, $page['total']);
        $family = array_values(array_filter(
            $page['items'],
            static fn (array $item): bool => $item['homeProductId'] === self::PRIVATE_PRODUCT_ID,
        ));
        self::assertCount(1, $family);
        self::assertSame(self::BEANS_ID, $family[0]['productId']);
        self::assertNull($family[0]['packId']);
        self::assertSame('Baked Beans', $family[0]['canonicalName']);
        self::assertSame('original unresolved pack wording', $family[0]['packText']);
        self::assertSame(2, count(array_filter($page['items'], static fn (array $item): bool =>
            $item['productId'] === self::BEANS_ID && $item['packId'] !== null)));
    }

    public function testPackOnlyCreationCannotBypassParentPublicationState(): void
    {
        $this->connection->executeStatement(
            "UPDATE products SET status = 'draft' WHERE id = :id",
            ['id' => self::BEANS_ID],
        );
        $this->expectException(\DomainException::class);
        $this->store->createHomeProduct(
            self::PRIVATE_PRODUCT_ID,
            self::HOME_ID,
            null,
            self::BEANS_PACK_ONE,
            null,
            null,
            '1 kg',
            null,
            new DateTimeImmutable('2026-09-14T12:00:00+00:00'),
        );
    }
    public function testPagesAreStableTypedAndExposeOnlyAuthorizedAliases(): void
    {
        $first = $this->store->itemMaster(self::HOME_ID, '', null, null, 2, 0);
        $second = $this->store->itemMaster(self::HOME_ID, '', null, null, 2, 2);

        self::assertSame(3, $first['total']);
        self::assertSame(3, $second['total']);
        self::assertSame(
            [self::BEANS_PACK_ONE, self::BEANS_PACK_TWO],
            array_column($first['items'], 'packId'),
        );
        self::assertSame([self::RICE_PACK], array_column($second['items'], 'packId'));
        self::assertSame([], array_intersect(
            array_column($first['items'], 'packId'),
            array_column($second['items'], 'packId'),
        ));

        $beans = $first['items'][0];
        self::assertSame('Baked Beans', $beans['canonicalName']);
        self::assertSame('Acme', $beans['brand']);
        self::assertSame(self::CATEGORY_ID, $beans['categoryId']);
        self::assertSame('Canned', $beans['categoryName']);
        self::assertSame('1 kg', $beans['packText']);
        self::assertSame('published', $beans['packStatus']);
        self::assertSame(self::HOME_PRODUCT_ID, $beans['homeProductId']);
        self::assertSame('active', $beans['homeProductStatus']);
        self::assertSame('3.50000000', $beans['quantity']);
        self::assertSame(['Bulk beans', 'Kidney beans'], $beans['aliases']);
        self::assertNotContains('Other home secret', $beans['aliases']);
        self::assertNotContains('Unapproved wording', $beans['aliases']);

        $uncounted = $first['items'][1];
        self::assertNull($uncounted['homeProductId']);
        self::assertNull($uncounted['homeProductStatus']);
        self::assertSame('0', $uncounted['quantity']);
        self::assertSame(['Kidney beans'], $uncounted['aliases']);
    }

    public function testSearchAndCategoryFiltersRespectAliasScopeAndPackIdentity(): void
    {
        $homeAlias = $this->store->itemMaster(self::HOME_ID, 'bulk beans', null, null, 100, 0);
        $otherHomeAlias = $this->store->itemMaster(self::HOME_ID, 'other home secret', null, null, 100, 0);
        $globalAlias = $this->store->itemMaster(self::HOME_ID, 'kidney beans', null, null, 100, 0);
        $category = $this->store->itemMaster(self::HOME_ID, '', self::OTHER_CATEGORY_ID, null, 100, 0);

        self::assertSame([self::BEANS_PACK_ONE], array_column($homeAlias['items'], 'packId'));
        self::assertSame(0, $otherHomeAlias['total']);
        self::assertSame(
            [self::BEANS_PACK_ONE, self::BEANS_PACK_TWO],
            array_column($globalAlias['items'], 'packId'),
        );
        self::assertSame([self::RICE_PACK], array_column($category['items'], 'packId'));
    }

    public function testSelectedPublishedProductAndPackCanBecomeAHomeProduct(): void
    {
        $id = '01912345-6789-7abc-bdef-1123456789ab';
        $this->store->createHomeProduct(
            $id,
            self::HOME_ID,
            self::RICE_ID,
            self::RICE_PACK,
            null,
            null,
            '2 kg',
            null,
            new DateTimeImmutable('2026-08-11T10:00:00+00:00'),
        );

        $stored = $this->store->homeProduct(self::HOME_ID, $id);
        self::assertNotNull($stored);
        self::assertSame(self::RICE_ID, $stored['productId']);
        self::assertSame(self::RICE_PACK, $stored['packId']);
        self::assertSame('Rice', $stored['productName']);
    }

    public function testPrivateProductsJoinTheItemMasterWithoutPretendingToBeCatalogRows(): void
    {
        $this->insertPrivateCategory();
        $this->insertPrivateProduct();
        $this->connection->insert('inventory_balances', [
            'home_id' => self::HOME_ID,
            'home_product_id' => self::PRIVATE_PRODUCT_ID,
            'quantity' => '4.00000000',
        ]);

        $page = $this->store->itemMaster(
            self::HOME_ID,
            'sorghum',
            null,
            self::HOME_CATEGORY_ID,
            100,
            0,
        );

        self::assertSame(1, $page['total']);
        $private = $page['items'][0];
        self::assertNull($private['productId']);
        self::assertNull($private['packId']);
        self::assertNull($private['categoryId']);
        self::assertNull($private['packStatus']);
        self::assertSame(self::PRIVATE_PRODUCT_ID, $private['homeProductId']);
        self::assertSame(self::HOME_CATEGORY_ID, $private['homeCategoryId']);
        self::assertSame('home', $private['categorySource']);
        self::assertSame('Dry goods', $private['categoryName']);
        self::assertSame('4.00000000', $private['quantity']);
        self::assertSame([], $private['aliases']);
    }

    public function testHouseholdCategoryFiltersCatalogProductsWithoutReplacingCanonicalCacheLabels(): void
    {
        $this->insertPrivateCategory();
        $at = new DateTimeImmutable('2026-09-12T10:00:00+00:00');
        $saved = $this->store->updateHomeProduct(
            self::HOME_ID,
            self::HOME_PRODUCT_ID,
            false,
            null,
            null,
            false,
            null,
            true,
            self::HOME_CATEGORY_ID,
            null,
            1,
            $at,
        );
        self::assertSame('updated', $saved['status']);
        $filtered = $this->store->itemMaster(self::HOME_ID, '', null, self::HOME_CATEGORY_ID, 100, 0);
        self::assertSame(1, $filtered['total']);
        $item = $filtered['items'][0];
        self::assertSame(self::BEANS_PACK_ONE, $item['packId']);
        self::assertNull($item['categoryId']);
        self::assertSame('Dry goods', $item['categoryName']);
        self::assertSame('home', $item['categorySource']);
        self::assertSame(self::HOME_CATEGORY_ID, $item['homeCategoryId']);
        self::assertSame(self::CATEGORY_ID, $item['catalogCategoryId']);
        self::assertSame('Canned', $item['catalogCategoryName']);
        self::assertSame(
            0,
            $this->store->itemMaster(self::OTHER_HOME_ID, '', null, self::HOME_CATEGORY_ID, 100, 0)['total'],
        );

        $this->connection->executeStatement('ALTER TABLE inventory_balances ADD revision INTEGER NOT NULL DEFAULT 0');
        $this->connection->executeStatement(
            'CREATE TABLE stock_threshold_preferences (home_id TEXT, home_product_id TEXT, ' .
                'minimum_quantity TEXT, always_keep INTEGER, never_suggest INTEGER)',
        );
        $stock = $this->store->stock(self::HOME_ID, '', null, self::HOME_CATEGORY_ID, 100, 0);
        self::assertCount(1, $stock);
        self::assertSame(self::HOME_CATEGORY_ID, $stock[0]['homeCategoryId']);
        self::assertNull($stock[0]['categoryId']);
        self::assertSame('home', $stock[0]['categorySource']);
        self::assertSame('Dry goods', $stock[0]['categoryName']);
        self::assertSame('Dry goods', $stock[0]['category']);
    }

    public function testCategoryAndPrivateProductArchiveSafeguardsAreRevisionBound(): void
    {
        $this->insertPrivateCategory();
        $this->insertPrivateProduct();
        $at = new DateTimeImmutable('2026-08-24T10:00:00+00:00');

        $blocked = $this->store->updateHomeCategory(
            self::HOME_ID,
            self::HOME_CATEGORY_ID,
            null,
            null,
            'archived',
            1,
            $at,
        );
        self::assertSame('category-in-use', $blocked['status']);

        $archivedProduct = $this->store->updateHomeProduct(
            self::HOME_ID,
            self::PRIVATE_PRODUCT_ID,
            false,
            null,
            null,
            false,
            null,
            false,
            null,
            'archived',
            1,
            $at,
        );
        self::assertSame('updated', $archivedProduct['status']);
        self::assertSame('archived', $archivedProduct['record']['status']);

        $archivedCategory = $this->store->updateHomeCategory(
            self::HOME_ID,
            self::HOME_CATEGORY_ID,
            'Shelf-stable',
            'shelf stable',
            'archived',
            1,
            $at,
        );
        self::assertSame('updated', $archivedCategory['status']);
        self::assertSame(2, $archivedCategory['record']['revision']);
        self::assertSame('2026-08-24T10:00:00Z', $archivedCategory['record']['updatedAt']);
        self::assertSame([], $this->store->categories(self::HOME_ID, false));
        self::assertCount(1, $this->store->categories(self::HOME_ID, true));
    }

    public function testRemovedCountAndReceiptLinesDoNotBlockProductArchival(): void
    {
        $this->insertPrivateCategory();
        $this->insertPrivateProduct();
        $this->connection->insert('stock_count_sessions', [
            'id' => 'count', 'home_id' => self::HOME_ID, 'status' => 'open',
        ]);
        $this->connection->insert('stock_count_lines', [
            'session_id' => 'count', 'home_id' => self::HOME_ID,
            'home_product_id' => self::PRIVATE_PRODUCT_ID, 'status' => 'confirmed',
        ]);
        $archive = fn(): array => $this->store->updateHomeProduct(
            self::HOME_ID,
            self::PRIVATE_PRODUCT_ID,
            false,
            null,
            null,
            false,
            null,
            false,
            null,
            'archived',
            1,
            new DateTimeImmutable('2026-09-12T12:00:00Z'),
        );
        self::assertSame('product-in-use', $archive()['status']);
        $this->connection->update('stock_count_lines', ['status' => 'removed'], ['session_id' => 'count']);
        $this->connection->insert('receipts', [
            'id' => 'receipt', 'home_id' => self::HOME_ID, 'status' => 'draft',
        ]);
        $this->connection->insert('receipt_lines', [
            'receipt_id' => 'receipt', 'home_id' => self::HOME_ID,
            'home_product_id' => self::PRIVATE_PRODUCT_ID, 'approval_status' => 'approved',
        ]);
        self::assertSame('product-in-use', $archive()['status']);
        $this->connection->update('receipt_lines', ['approval_status' => 'removed'], ['receipt_id' => 'receipt']);
        $saved = $archive();
        self::assertSame('updated', $saved['status']);
        self::assertSame('archived', $saved['record']['status']);
        self::assertSame(2, $saved['record']['revision']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_count_lines'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM receipt_lines'));
    }

    public function testCategoryUniqueConstraintRaceIsTranslatedToADomainConflict(): void
    {
        $candidate = '01912345-6789-7abc-cdef-3123456789ab';
        $this->connection->executeStatement(
            "CREATE TRIGGER inject_category_race BEFORE INSERT ON home_categories
             WHEN NEW.id = '$candidate'
             BEGIN
               INSERT INTO home_categories
                 (id, home_id, name, normalized_name, status, revision, created_at, updated_at, archived_at)
               VALUES
                 ('01912345-6789-7abc-ddef-3123456789ab', NEW.home_id, 'Race winner',
                  NEW.normalized_name, 'active', 1, NEW.created_at, NEW.updated_at, NULL);
             END",
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already exists');
        $this->store->createHomeCategory(
            $candidate,
            self::HOME_ID,
            'Race candidate',
            'race category',
            new DateTimeImmutable('2026-08-24T10:00:00+00:00'),
        );
    }

    public function testCategoryAndProductCasFailuresCannotReportUpdated(): void
    {
        $this->insertPrivateCategory();
        $this->insertPrivateProduct();
        $this->connection->executeStatement(
            "CREATE TRIGGER reject_category_cas BEFORE UPDATE OF revision ON home_categories
             WHEN OLD.id = '" . self::HOME_CATEGORY_ID . "'
             BEGIN SELECT RAISE(IGNORE); END",
        );
        self::assertSame('revision-conflict', $this->store->updateHomeCategory(
            self::HOME_ID,
            self::HOME_CATEGORY_ID,
            'Changed',
            'changed',
            null,
            1,
            new DateTimeImmutable('2026-08-24T10:00:00+00:00'),
        )['status']);

        $this->connection->executeStatement(
            "CREATE TRIGGER reject_product_cas BEFORE UPDATE OF revision ON home_products
             WHEN OLD.id = '" . self::PRIVATE_PRODUCT_ID . "'
             BEGIN SELECT RAISE(IGNORE); END",
        );
        self::assertSame('revision-conflict', $this->store->updateHomeProduct(
            self::HOME_ID,
            self::PRIVATE_PRODUCT_ID,
            true,
            'Changed product',
            'changed product',
            false,
            null,
            false,
            null,
            null,
            1,
            new DateTimeImmutable('2026-08-24T10:00:00+00:00'),
        )['status']);
    }

    public function testPrivateCategoryAssignmentCannotCrossTheHomeBoundary(): void
    {
        $this->connection->insert('home_categories', [
            'id' => self::HOME_CATEGORY_ID,
            'home_id' => self::OTHER_HOME_ID,
            'name' => 'Other home category',
            'normalized_name' => 'other home category',
            'status' => 'active',
            'revision' => 1,
            'created_at' => '2026-08-24 09:00:00',
            'updated_at' => '2026-08-24 09:00:00',
            'archived_at' => null,
        ]);
        try {
            $this->store->createHomeProduct(
                self::PRIVATE_PRODUCT_ID,
                self::HOME_ID,
                null,
                null,
                'Private item',
                'private item',
                null,
                self::HOME_CATEGORY_ID,
                new DateTimeImmutable('2026-08-24T10:00:00+00:00'),
            );
            self::fail('A category from another home was assigned.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('unavailable', $error->getMessage());
        }

        $this->connection->update('home_categories', ['home_id' => self::HOME_ID], [
            'id' => self::HOME_CATEGORY_ID,
        ]);
        $this->insertPrivateProduct();
        $this->connection->update('home_categories', ['home_id' => self::OTHER_HOME_ID], [
            'id' => self::HOME_CATEGORY_ID,
        ]);
        self::assertSame('category-unavailable', $this->store->updateHomeProduct(
            self::HOME_ID,
            self::PRIVATE_PRODUCT_ID,
            false,
            null,
            null,
            false,
            null,
            true,
            self::HOME_CATEGORY_ID,
            null,
            1,
            new DateTimeImmutable('2026-08-24T10:00:00+00:00'),
        )['status']);
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE categories (id TEXT PRIMARY KEY, canonical_name TEXT NOT NULL)',
            'CREATE TABLE products (
                id TEXT PRIMARY KEY, category_id TEXT NOT NULL, canonical_name TEXT NOT NULL,
                normalized_name TEXT NOT NULL, brand TEXT NOT NULL,
                normalized_brand TEXT NOT NULL, status TEXT NOT NULL
            )',
            'CREATE TABLE product_packs (
                id TEXT PRIMARY KEY, product_id TEXT NOT NULL, variant_id TEXT NULL,
                original_pack_text TEXT NOT NULL, status TEXT NOT NULL
            )',
            'CREATE TABLE product_aliases (
                id TEXT PRIMARY KEY, scope TEXT NOT NULL, home_id TEXT NULL,
                product_id TEXT NOT NULL, variant_id TEXT NULL, pack_id TEXT NULL,
                raw_alias TEXT NOT NULL, normalized_alias TEXT NOT NULL, status TEXT NOT NULL
            )',
            'CREATE TABLE home_categories (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, name TEXT NOT NULL,
                normalized_name TEXT NOT NULL, status TEXT NOT NULL, revision INTEGER NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, archived_at TEXT NULL,
                UNIQUE (home_id, normalized_name)
            )',
            'CREATE TABLE home_products (global_category_id TEXT, unit TEXT NOT NULL DEFAULT \'units\',
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, product_id TEXT NULL, pack_id TEXT NULL,
                private_name TEXT NULL, normalized_private_name TEXT NULL,
                original_pack_text TEXT NULL, home_category_id TEXT NULL,
                status TEXT NOT NULL, revision INTEGER NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE inventory_balances (
                home_id TEXT NOT NULL, home_product_id TEXT NOT NULL, quantity TEXT NOT NULL
            )',
            'CREATE TABLE stock_count_sessions (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, status TEXT NOT NULL
            )',
            'CREATE TABLE stock_count_lines (
                session_id TEXT NOT NULL, home_id TEXT NOT NULL, home_product_id TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'confirmed\'
            )',
            'CREATE TABLE receipts (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, status TEXT NOT NULL
            )',
            'CREATE TABLE receipt_lines (
                receipt_id TEXT NOT NULL, home_id TEXT NOT NULL, home_product_id TEXT NULL,
                approval_status TEXT NOT NULL DEFAULT \'pending\'
            )',
        ];
    }

    private function seedCatalog(): void
    {
        $this->connection->insert('categories', [
            'id' => self::CATEGORY_ID,
            'canonical_name' => 'Canned',
        ]);
        $this->connection->insert('categories', [
            'id' => self::OTHER_CATEGORY_ID,
            'canonical_name' => 'Grains',
        ]);
        $this->insertProduct(self::BEANS_ID, self::CATEGORY_ID, 'Baked Beans', 'baked beans', 'Acme', 'acme');
        $this->insertProduct(self::RICE_ID, self::OTHER_CATEGORY_ID, 'Rice', 'rice', '', '');
        $this->insertPack(self::BEANS_PACK_ONE, self::BEANS_ID, '1 kg');
        $this->insertPack(self::BEANS_PACK_TWO, self::BEANS_ID, '500 g');
        $this->insertPack(self::RICE_PACK, self::RICE_ID, '2 kg');
        $this->connection->insert('home_products', [
            'id' => self::HOME_PRODUCT_ID,
            'home_id' => self::HOME_ID,
            'product_id' => self::BEANS_ID,
            'pack_id' => self::BEANS_PACK_ONE,
            'private_name' => null,
            'normalized_private_name' => null,
            'original_pack_text' => '1 kg',
            'status' => 'active',
            'revision' => 1,
            'created_at' => '2026-08-11 09:00:00',
            'updated_at' => '2026-08-11 09:00:00',
        ]);
        $this->connection->insert('inventory_balances', [
            'home_id' => self::HOME_ID,
            'home_product_id' => self::HOME_PRODUCT_ID,
            'quantity' => '3.50000000',
        ]);
        $this->connection->insert('home_products', [
            'id' => self::OTHER_HOME_PRODUCT_ID,
            'home_id' => self::OTHER_HOME_ID,
            'product_id' => self::BEANS_ID,
            'pack_id' => self::BEANS_PACK_TWO,
            'private_name' => null,
            'normalized_private_name' => null,
            'original_pack_text' => '500 g',
            'status' => 'active',
            'revision' => 1,
            'created_at' => '2026-08-11 09:00:00',
            'updated_at' => '2026-08-11 09:00:00',
        ]);
        $this->connection->insert('inventory_balances', [
            'home_id' => self::OTHER_HOME_ID,
            'home_product_id' => self::OTHER_HOME_PRODUCT_ID,
            'quantity' => '99.00000000',
        ]);
        $this->insertAlias('alias-global', 'global', null, self::BEANS_ID, null, 'Kidney beans', 'kidney beans');
        $this->insertAlias(
            'alias-home',
            'home',
            self::HOME_ID,
            self::BEANS_ID,
            self::BEANS_PACK_ONE,
            'Bulk beans',
            'bulk beans',
        );
        $this->insertAlias(
            'alias-other',
            'home',
            self::OTHER_HOME_ID,
            self::BEANS_ID,
            self::BEANS_PACK_ONE,
            'Other home secret',
            'other home secret',
        );
        $this->insertAlias(
            'alias-pending',
            'global',
            null,
            self::BEANS_ID,
            self::BEANS_PACK_ONE,
            'Unapproved wording',
            'unapproved wording',
            'pending',
        );
    }

    private function insertProduct(
        string $id,
        string $categoryId,
        string $name,
        string $normalizedName,
        string $brand,
        string $normalizedBrand,
    ): void {
        $this->connection->insert('products', [
            'id' => $id,
            'category_id' => $categoryId,
            'canonical_name' => $name,
            'normalized_name' => $normalizedName,
            'brand' => $brand,
            'normalized_brand' => $normalizedBrand,
            'status' => 'published',
        ]);
    }

    private function insertPrivateCategory(): void
    {
        $this->connection->insert('home_categories', [
            'id' => self::HOME_CATEGORY_ID,
            'home_id' => self::HOME_ID,
            'name' => 'Dry goods',
            'normalized_name' => 'dry goods',
            'status' => 'active',
            'revision' => 1,
            'created_at' => '2026-08-24 09:00:00',
            'updated_at' => '2026-08-24 09:00:00',
            'archived_at' => null,
        ]);
    }

    private function insertPrivateProduct(): void
    {
        $this->connection->insert('home_products', [
            'id' => self::PRIVATE_PRODUCT_ID,
            'home_id' => self::HOME_ID,
            'product_id' => null,
            'pack_id' => null,
            'private_name' => 'Sorghum meal',
            'normalized_private_name' => 'sorghum meal',
            'original_pack_text' => '1 kg',
            'home_category_id' => self::HOME_CATEGORY_ID,
            'status' => 'active',
            'revision' => 1,
            'created_at' => '2026-08-24 09:00:00',
            'updated_at' => '2026-08-24 09:00:00',
        ]);
    }

    private function insertPack(string $id, string $productId, string $packText): void
    {
        $this->connection->insert('product_packs', [
            'id' => $id,
            'product_id' => $productId,
            'variant_id' => null,
            'original_pack_text' => $packText,
            'status' => 'published',
        ]);
    }

    private function insertAlias(
        string $id,
        string $scope,
        ?string $homeId,
        string $productId,
        ?string $packId,
        string $rawAlias,
        string $normalizedAlias,
        string $status = 'approved',
    ): void {
        $this->connection->insert('product_aliases', [
            'id' => $id,
            'scope' => $scope,
            'home_id' => $homeId,
            'product_id' => $productId,
            'variant_id' => null,
            'pack_id' => $packId,
            'raw_alias' => $rawAlias,
            'normalized_alias' => $normalizedAlias,
            'status' => $status,
        ]);
    }
}
