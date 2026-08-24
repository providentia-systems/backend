<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Inventory\Infrastructure\Doctrine\DbalCatalogContributionSourceReader;

final class CatalogContributionSourceReaderTest extends TestCase
{
    public function testSourceLookupIsHomeBoundActiveAndReturnsExactPublishedBinding(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE home_products (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, product_id TEXT NULL,
                pack_id TEXT NULL, status TEXT NOT NULL
            )',
        );
        $connection->insert('home_products', [
            'id' => 'home-product-1',
            'home_id' => 'home-1',
            'product_id' => 'product-1',
            'pack_id' => 'pack-1',
            'status' => 'active',
        ]);
        $reader = new DbalCatalogContributionSourceReader($connection);

        self::assertSame([
            'productId' => 'product-1',
            'packId' => 'pack-1',
        ], $reader->activeHomeProduct('home-1', 'home-product-1'));
        self::assertNull($reader->activeHomeProduct('other-home', 'home-product-1'));

        $connection->update('home_products', ['status' => 'archived'], ['id' => 'home-product-1']);
        self::assertNull($reader->activeHomeProduct('home-1', 'home-product-1'));
    }
}
