<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogGovernanceStore;

final class CatalogMergeStoreTest extends TestCase
{
    private Connection $connection;
    private DbalCatalogGovernanceStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ($this->schema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->connection->insert('products', [
            'id' => 'survivor',
            'canonical_name' => 'Rice',
            'brand' => '',
            'status' => 'published',
            'revision' => 1,
            'updated_at' => '2026-07-30 12:00:00',
        ]);
        $this->connection->insert('products', [
            'id' => 'duplicate',
            'canonical_name' => 'Long Grain Rice',
            'brand' => '',
            'status' => 'published',
            'revision' => 1,
            'updated_at' => '2026-07-30 12:00:00',
        ]);
        $this->connection->insert('product_packs', [
            'id' => 'pack-1',
            'product_id' => 'duplicate',
            'original_pack_text' => '1 kg',
            'status' => 'published',
        ]);
        $this->connection->insert('home_products', ['id' => 'home-product-1', 'product_id' => 'duplicate']);
        $this->store = new DbalCatalogGovernanceStore(
            $this->connection,
            new SequenceUuidGenerator(),
        );
    }

    public function testMergeRelinksWithoutExposingHomeDataAndCanBeReversed(): void
    {
        $preview = $this->store->mergePreview('survivor', ['duplicate']);

        /** @var array<string, int> $affectedCounts */
        $affectedCounts = $preview['affectedCounts'];
        self::assertTrue((bool) $preview['eligible']);
        self::assertSame(1, $affectedCounts['packs']);
        self::assertSame(1, $affectedCounts['homeReferences']);
        self::assertArrayNotHasKey('homeIds', $preview);

        $applied = $this->store->applyMerge(
            'merge-1',
            'survivor',
            1,
            ['duplicate' => 1],
            'Same canonical grocery product',
            'curator-1',
            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
        );

        self::assertSame('applied', $applied['status']);
        self::assertSame('survivor', $this->productFor('product_packs', 'pack-1'));
        self::assertSame('survivor', $this->productFor('home_products', 'home-product-1'));
        self::assertSame('merged', $this->statusFor('duplicate'));

        $reversed = $this->store->reverseMerge(
            'merge-1',
            1,
            'Products are meaningfully different',
            'curator-2',
            new DateTimeImmutable('2026-07-30T12:05:00+00:00'),
        );

        self::assertSame('reversed', $reversed['status']);
        self::assertSame('duplicate', $this->productFor('product_packs', 'pack-1'));
        self::assertSame('duplicate', $this->productFor('home_products', 'home-product-1'));
        self::assertSame('published', $this->statusFor('duplicate'));
    }

    public function testProposalWorkbenchOmitsSubmitterAttribution(): void
    {
        $this->connection->insert('catalog_proposals', [
            'id' => 'proposal-1',
            'proposal_type' => 'product',
            'proposal_json' => json_encode([
                'canonicalName' => 'Brown rice',
                'brand' => '',
                'categoryId' => 'category-1',
            ], JSON_THROW_ON_ERROR),
            'moderation_status' => 'pending',
            'duplicate_entity_id' => null,
            'revision' => 1,
            'submitted_by_user_id' => 'user-private',
            'created_at' => '2026-08-04 11:00:00',
            'updated_at' => '2026-08-04 11:00:00',
        ]);

        $rows = $this->store->workbench('proposals', 50, 0);

        self::assertCount(1, $rows);
        self::assertArrayNotHasKey('submittedByUserId', $rows[0]);
        self::assertArrayNotHasKey('submitted_by_user_id', $rows[0]);
        self::assertArrayNotHasKey('homeId', $rows[0]);
        self::assertArrayNotHasKey('home_id', $rows[0]);
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE products (
                id VARCHAR(36) PRIMARY KEY, canonical_name VARCHAR(191) NOT NULL,
                brand VARCHAR(120) NOT NULL, status VARCHAR(32) NOT NULL,
                revision INTEGER NOT NULL, updated_at DATETIME NOT NULL
            )',
            'CREATE TABLE catalog_proposals (
                id VARCHAR(36) PRIMARY KEY, proposal_type VARCHAR(32) NOT NULL,
                proposal_json TEXT NOT NULL, moderation_status VARCHAR(24) NOT NULL,
                duplicate_entity_id VARCHAR(36) NULL, revision INTEGER NOT NULL,
                submitted_by_user_id VARCHAR(36) NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
            )',
            'CREATE TABLE product_variants (
                id VARCHAR(36) PRIMARY KEY, product_id VARCHAR(36) NOT NULL,
                normalized_label VARCHAR(191) NOT NULL, status VARCHAR(32) NOT NULL
            )',
            'CREATE TABLE product_packs (
                id VARCHAR(36) PRIMARY KEY, product_id VARCHAR(36) NOT NULL,
                original_pack_text VARCHAR(191) NOT NULL, status VARCHAR(32) NOT NULL
            )',
            'CREATE TABLE product_aliases (
                id VARCHAR(36) PRIMARY KEY, product_id VARCHAR(36) NOT NULL,
                normalized_alias VARCHAR(191) NOT NULL, scope VARCHAR(16) NOT NULL,
                status VARCHAR(32) NOT NULL
            )',
            'CREATE TABLE home_products (
                id VARCHAR(36) PRIMARY KEY, product_id VARCHAR(36) NULL
            )',
            'CREATE TABLE catalog_icons (
                id VARCHAR(36) PRIMARY KEY, target_type TEXT NOT NULL,
                target_id TEXT NOT NULL, status VARCHAR(24) NOT NULL,
                created_at DATETIME NOT NULL
            )',
            'CREATE TABLE catalog_merge_events (
                id VARCHAR(36) PRIMARY KEY, survivor_id TEXT NOT NULL,
                merged_ids_json TEXT NOT NULL, plan_json TEXT NOT NULL,
                reason TEXT NOT NULL, reversed_at DATETIME NULL,
                status VARCHAR(24) NOT NULL, revision INTEGER NOT NULL,
                applied_by_user_id VARCHAR(36) NULL, applied_at DATETIME NULL,
                reversed_by_user_id VARCHAR(36) NULL, reverse_reason VARCHAR(500) NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NULL
            )',
            'CREATE TABLE catalog_product_redirects (
                duplicate_product_id VARCHAR(36) NOT NULL,
                survivor_product_id VARCHAR(36) NOT NULL,
                merge_event_id VARCHAR(36) NOT NULL, status VARCHAR(24) NOT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                PRIMARY KEY (duplicate_product_id, merge_event_id)
            )',
            'CREATE TABLE catalog_merge_relinks (
                merge_event_id VARCHAR(36) NOT NULL,
                duplicate_product_id VARCHAR(36) NOT NULL,
                reference_type VARCHAR(32) NOT NULL, reference_id VARCHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (merge_event_id, reference_type, reference_id)
            )',
            'CREATE TABLE catalog_revisions (
                id VARCHAR(36) PRIMARY KEY, entity_type TEXT NOT NULL,
                entity_id TEXT NOT NULL, entity_key VARCHAR(191) NOT NULL,
                before_json TEXT NOT NULL,
                after_json TEXT NOT NULL, reason TEXT NOT NULL,
                actor_user_id VARCHAR(36) NULL, operation_id VARCHAR(36) NULL,
                created_at DATETIME NOT NULL
            )',
            'CREATE TABLE audit_events (
                id VARCHAR(36) PRIMARY KEY, home_id VARCHAR(36) NULL,
                actor_user_id VARCHAR(36) NULL, action VARCHAR(120) NOT NULL,
                target_type VARCHAR(80) NOT NULL, target_id VARCHAR(64) NOT NULL,
                details TEXT NOT NULL, occurred_at DATETIME NOT NULL
            )',
        ];
    }

    private function productFor(string $table, string $id): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT product_id FROM ' . $table . ' WHERE id = :id',
            ['id' => $id],
        );
    }

    private function statusFor(string $productId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT status FROM products WHERE id = :id',
            ['id' => $productId],
        );
    }
}
