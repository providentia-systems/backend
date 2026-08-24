<?php

declare(strict_types=1);

namespace Providentia\Inventory\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Providentia\Catalog\Application\CatalogMergeHomeProductGateway;

final readonly class DbalCatalogMergeHomeProductGateway implements CatalogMergeHomeProductGateway
{
    public function __construct(private Connection $connection)
    {
    }

    public function references(string $productId): array
    {
        $sql = 'SELECT id FROM home_products WHERE product_id = :product ORDER BY id';
        if (! $this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $sql .= ' FOR UPDATE';
        }

        return array_map(
            static fn (mixed $id): string => (string) $id,
            $this->connection->fetchFirstColumn($sql, ['product' => $productId]),
        );
    }

    public function pointsTo(string $homeProductId, string $productId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM home_products WHERE id = :id AND product_id = :product',
            ['id' => $homeProductId, 'product' => $productId],
        ) === 1;
    }

    public function relink(string $homeProductId, string $fromProductId, string $toProductId): bool
    {
        return $this->connection->executeStatement(
            'UPDATE home_products SET product_id = :target
             WHERE id = :id AND product_id = :source',
            ['target' => $toProductId, 'id' => $homeProductId, 'source' => $fromProductId],
        ) === 1;
    }
}
