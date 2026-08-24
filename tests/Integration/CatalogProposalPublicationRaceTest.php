<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use DomainException;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogGovernanceStore;
use Providentia\Inventory\Infrastructure\Doctrine\DbalCatalogMergeHomeProductGateway;

final class CatalogProposalPublicationRaceTest extends TestCase
{
    public function testCategoryUniqueRaceIsTranslatedToDomainFailure(): void
    {
        $connection = $this->connection();
        $connection->insert('categories', $this->category('category-existing', 'Dry Goods', 'dry goods'));
        $store = new DbalCatalogGovernanceStore(
            $connection,
            new SequenceUuidGenerator(),
            new DbalCatalogMergeHomeProductGateway($connection),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('published concurrently');
        $store->publishProposal(
            [
                'id' => 'proposal-category',
                'proposalType' => 'category',
                'payload' => ['canonicalName' => 'Dry Goods'],
            ],
            'category-candidate',
            'curator',
            new DateTimeImmutable('2026-08-24T12:00:00+00:00'),
        );
    }

    public function testProductUniqueRaceIsTranslatedToDomainFailure(): void
    {
        $connection = $this->connection();
        $connection->insert('categories', $this->category('category-1', 'Breakfast', 'breakfast'));
        $connection->insert('products', [
            'id' => 'product-existing',
            'category_id' => 'category-1',
            'canonical_name' => 'Rolled oats',
            'normalized_name' => 'rolled oats',
            'brand' => 'Example',
            'normalized_brand' => 'example',
            'status' => 'published',
            'revision' => 1,
            'created_at' => '2026-08-24 11:00:00',
            'updated_at' => '2026-08-24 11:00:00',
        ]);
        $store = new DbalCatalogGovernanceStore(
            $connection,
            new SequenceUuidGenerator(),
            new DbalCatalogMergeHomeProductGateway($connection),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('published concurrently');
        $store->publishProposal(
            [
                'id' => 'proposal-product',
                'proposalType' => 'product',
                'payload' => [
                    'canonicalName' => 'Rolled oats',
                    'brand' => 'Example',
                    'categoryId' => 'category-1',
                ],
            ],
            'product-candidate',
            'curator',
            new DateTimeImmutable('2026-08-24T12:00:00+00:00'),
        );
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE categories (
                id TEXT PRIMARY KEY, parent_id TEXT NULL, canonical_name TEXT NOT NULL,
                normalized_name TEXT NOT NULL UNIQUE, status TEXT NOT NULL,
                revision INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE products (
                id TEXT PRIMARY KEY, category_id TEXT NOT NULL, canonical_name TEXT NOT NULL,
                normalized_name TEXT NOT NULL, brand TEXT NOT NULL, normalized_brand TEXT NOT NULL,
                status TEXT NOT NULL, revision INTEGER NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                UNIQUE (category_id, normalized_name, normalized_brand)
            )',
        );

        return $connection;
    }

    /** @return array<string, int|string|null> */
    private function category(string $id, string $name, string $normalizedName): array
    {
        return [
            'id' => $id,
            'parent_id' => null,
            'canonical_name' => $name,
            'normalized_name' => $normalizedName,
            'status' => 'published',
            'revision' => 1,
            'created_at' => '2026-08-24 11:00:00',
            'updated_at' => '2026-08-24 11:00:00',
        ];
    }
}
