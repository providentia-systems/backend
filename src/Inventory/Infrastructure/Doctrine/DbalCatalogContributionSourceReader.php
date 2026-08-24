<?php

declare(strict_types=1);

namespace Providentia\Inventory\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Providentia\Catalog\Application\CatalogContributionSourceReader;

final class DbalCatalogContributionSourceReader implements CatalogContributionSourceReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function activeHomeProduct(string $homeId, string $homeProductId): ?array
    {
        $sql = 'SELECT product_id AS productId, pack_id AS packId
                FROM home_products
                WHERE id = :id AND home_id = :home AND status = :status';
        if (! $this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->connection->fetchAssociative(
            $sql,
            ['id' => $homeProductId, 'home' => $homeId, 'status' => 'active'],
        );

        return $row === false ? null : [
            'productId' => $row['productId'] === null ? null : (string) $row['productId'],
            'packId' => $row['packId'] === null ? null : (string) $row['packId'],
        ];
    }
}
