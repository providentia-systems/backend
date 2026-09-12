<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DomainException;
use PHPUnit\Framework\TestCase;
use Providentia\Administration\Infrastructure\Doctrine\DbalOperatorWorkspaceStore;
use Providentia\Catalog\Domain\PackMeasure;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogGovernanceStore;
use Providentia\Inventory\Infrastructure\Doctrine\DbalCatalogMergeHomeProductGateway;

final class CatalogMaintenanceTest extends TestCase
{
    private Connection $connection;
    private DbalCatalogGovernanceStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (
            [
                'CREATE TABLE categories (id TEXT PRIMARY KEY, parent_id TEXT, canonical_name TEXT,
                normalized_name TEXT UNIQUE, status TEXT, revision INTEGER, created_at TEXT, updated_at TEXT)',
                'CREATE TABLE products (id TEXT PRIMARY KEY, category_id TEXT, canonical_name TEXT,
                normalized_name TEXT, brand TEXT, normalized_brand TEXT, status TEXT, revision INTEGER,
                created_at TEXT, updated_at TEXT, UNIQUE(category_id, normalized_name, normalized_brand))',
                'CREATE TABLE units (id TEXT PRIMARY KEY, symbol TEXT, name TEXT, dimension TEXT,
                base_factor TEXT, status TEXT, revision INTEGER, created_at TEXT, updated_at TEXT,
                UNIQUE(symbol, dimension))',
                'CREATE TABLE product_packs (id TEXT PRIMARY KEY, product_id TEXT, variant_id TEXT, unit_id TEXT,
                source_key TEXT UNIQUE, original_pack_text TEXT, amount TEXT, normalized_base_amount TEXT,
                multiplicity INTEGER, status TEXT, revision INTEGER, created_at TEXT, updated_at TEXT)',
                'CREATE TABLE home_products (id TEXT PRIMARY KEY, home_id TEXT,
                product_id TEXT, pack_id TEXT, status TEXT)',
                'CREATE TABLE catalog_revisions (id TEXT PRIMARY KEY, entity_type TEXT,
                entity_id TEXT, entity_key TEXT,
                before_json TEXT, after_json TEXT, reason TEXT, actor_user_id TEXT,
                operation_id TEXT, created_at TEXT)',
                'CREATE TABLE audit_events (id TEXT PRIMARY KEY, home_id TEXT, actor_user_id TEXT,
                action TEXT, target_type TEXT, target_id TEXT, details TEXT, occurred_at TEXT)',
                'CREATE TABLE inventory_balances (home_id TEXT, home_product_id TEXT, quantity TEXT,
                last_movement_id TEXT, revision INTEGER, updated_at TEXT, PRIMARY KEY(home_id, home_product_id))',
            ] as $sql
        ) {
            $this->connection->executeStatement($sql);
        }
        $this->store = new DbalCatalogGovernanceStore(
            $this->connection,
            new SequenceUuidGenerator(),
            new DbalCatalogMergeHomeProductGateway($this->connection),
        );
    }

    public function testCategoryLifecycleRetainsRevisionAndAuditHistory(): void
    {
        $created = $this->save('category', 'category', ['canonicalName' => 'Pantry']);
        self::assertSame(1, $created['revision']);
        $edited = $this->save('category', 'category', ['canonicalName' => 'Dry food'], 1);
        self::assertSame('Dry food', $edited['fields']['canonicalName']);
        $archived = $this->save('category', 'category', ['canonicalName' => 'Dry food'], 2, 'archived');
        self::assertSame('archived', $archived['status']);
        $restored = $this->save('category', 'category', ['canonicalName' => 'Dry food'], 3);
        self::assertSame(4, $restored['revision']);
        self::assertCount(1, $this->store->entities('category', 0));
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_events'));
        $this->expectException(DomainException::class);
        $this->save('category', 'category', ['canonicalName' => 'Lost update'], 1);
    }

    public function testActiveProductPreventsCategoryArchival(): void
    {
        $this->save('category', 'category', ['canonicalName' => 'Pantry']);
        $this->save('product', 'product', [
            'canonicalName' => 'Rice',
            'brand' => '',
            'categoryId' => 'category',
        ]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('in use');
        $this->save('category', 'category', ['canonicalName' => 'Pantry'], 1, 'archived');
    }

    public function testNormalizedMeasuresAreExactAndUsedUnitFactorsCannotChange(): void
    {
        $this->save('category', 'category', ['canonicalName' => 'Pantry']);
        $this->save('product', 'product', [
            'canonicalName' => 'Rice',
            'brand' => '',
            'categoryId' => 'category',
        ]);
        $unit = ['symbol' => 'kg', 'name' => 'Kilogram', 'dimension' => 'mass', 'baseFactor' => '1000'];
        $this->save('unit', 'unit', $unit);
        $this->save('pack', 'pack', [
            'productId' => 'product',
            'variantId' => null,
            'unitId' => 'unit',
            'originalPackText' => '3 x 0.125 kg',
            'amount' => '0.125',
            'multiplicity' => '3',
        ]);
        self::assertSame(
            '375.00000000',
            $this->connection->fetchOne('SELECT normalized_base_amount FROM product_packs WHERE id = ?', [
                'pack',
            ]),
        );
        self::assertSame('0.00000001', PackMeasure::normalize('0.0001', '0.0001', 1));
        $this->expectException(DomainException::class);
        $unit['baseFactor'] = '1';
        $this->save('unit', 'unit', $unit, 1);
    }

    public function testApprovedProductWithoutAMeasureStillHasASelectablePack(): void
    {
        $this->save('category', 'category', ['canonicalName' => 'Pantry']);
        $this->connection->transactional(fn (): array => $this->store->publishProposal(
            ['id' => 'proposal', 'proposalType' => 'product', 'payload' => [
                'canonicalName' => 'Rice', 'brand' => '', 'categoryId' => 'category',
            ]],
            'product',
            'curator',
            new DateTimeImmutable('2026-09-12T12:00:00Z'),
        ));
        $pack = $this->connection->fetchAssociative('SELECT * FROM product_packs WHERE product_id = ?', ['product']);
        self::assertIsArray($pack);
        self::assertSame('Unspecified pack', $pack['original_pack_text']);
        self::assertSame('published', $pack['status']);
        self::assertNull($pack['normalized_base_amount']);
    }

    public function testProductMasterFiltersRelatedPacksAndExcludesPrivateAliases(): void
    {
        $this->save('category', 'category', ['canonicalName' => 'Pantry']);
        foreach (['rice', 'beans'] as $id) {
            $this->save('product', $id, ['canonicalName' => $id, 'brand' => '', 'categoryId' => 'category']);
        }
        $packs = $this->store->entities('pack', 0, 'rice');
        self::assertCount(1, $packs);
        self::assertSame('rice', $packs[0]['fields']['productId']);
        self::assertCount(1, $this->store->entities('category', 0, 'rice'));
        $this->connection->executeStatement('CREATE TABLE product_aliases (id TEXT PRIMARY KEY,
            scope TEXT, home_id TEXT, product_id TEXT, variant_id TEXT, pack_id TEXT,
            raw_alias TEXT, status TEXT, revision INTEGER)');
        foreach (['public' => null, 'private' => 'home'] as $id => $home) {
            $this->connection->insert('product_aliases', [
                'id' => $id, 'scope' => $home === null ? 'global' : 'home', 'home_id' => $home,
                'product_id' => 'rice', 'raw_alias' => $id, 'status' => 'approved', 'revision' => 1,
            ]);
        }
        $aliases = $this->store->entities('alias', 0, 'rice');
        self::assertCount(1, $aliases);
        self::assertSame('public', $aliases[0]['fields']['rawAlias']);
        self::assertSame([], $this->store->entities('alias', 0, 'beans'));
        self::assertSame([], $this->store->entities('pack', 100, 'rice'));
    }

    public function testOperatorStockUsesTheActualTenantProductBalanceKey(): void
    {
        $this->connection->insert('inventory_balances', [
            'home_id' => 'home',
            'home_product_id' => 'product',
            'quantity' => '2.5',
            'revision' => 3,
        ]);
        $this->connection->insert('inventory_balances', [
            'home_id' => 'other-home',
            'home_product_id' => 'private',
            'quantity' => '99',
            'revision' => 1,
        ]);
        $rows = new DbalOperatorWorkspaceStore($this->connection)->records('home', 'stock', 0);
        self::assertCount(1, $rows);
        self::assertSame('product', $rows[0]['home_product_id']);
        self::assertSame('2.5', $rows[0]['quantity']);
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function save(
        string $type,
        string $id,
        array $fields,
        int $revision = 0,
        string $status = 'published',
    ): array {
        return $this->connection->transactional(
            fn(): array => $this->store->saveEntity(
                $type,
                $id,
                $fields,
                $status,
                $revision,
                'Reviewed catalog correction',
                'curator',
                new DateTimeImmutable('2026-09-12T12:00:00Z'),
            ),
        );
    }
}
