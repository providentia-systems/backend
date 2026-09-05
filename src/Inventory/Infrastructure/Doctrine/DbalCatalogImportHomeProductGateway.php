<?php

declare(strict_types=1);

namespace Providentia\Inventory\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Providentia\Catalog\Application\CatalogImportHomeProductGateway;

final readonly class DbalCatalogImportHomeProductGateway implements CatalogImportHomeProductGateway
{
    public function __construct(private Connection $connection, private \Providentia\Access\Application\AccessService $access)
    {
    }

    public function matchingActiveId(
        string $homeId,
        ?string $productId,
        ?string $packId,
        ?string $normalizedPrivateName,
    ): ?string {
        if ($productId !== null) {
            $id = $this->connection->fetchOne(
                'SELECT id FROM home_products
                 WHERE home_id = :home AND product_id = :product AND status = :active
                   AND ((pack_id = :pack) OR (pack_id IS NULL AND :pack IS NULL))
                 ORDER BY id LIMIT 1',
                ['home' => $homeId, 'product' => $productId, 'pack' => $packId, 'active' => 'active'],
            );

            return $id === false ? null : (string) $id;
        }
        if ($normalizedPrivateName === null || $normalizedPrivateName === '') {
            return null;
        }
        $id = $this->connection->fetchOne(
            'SELECT id FROM home_products
             WHERE home_id = :home AND normalized_private_name = :name AND status = :active
             ORDER BY id LIMIT 1',
            ['home' => $homeId, 'name' => $normalizedPrivateName, 'active' => 'active'],
        );

        return $id === false ? null : (string) $id;
    }

    public function create(
        string $id,
        string $homeId,
        ?string $productId,
        ?string $packId,
        ?string $privateName,
        ?string $normalizedPrivateName,
        ?string $originalPackText,
        DateTimeImmutable $at,
    ): void {
        $this->access->requireCapacity('home', $homeId, 'products.total');
        $this->connection->insert('home_products', [
            'id' => $id,
            'home_id' => $homeId,
            'product_id' => $productId,
            'pack_id' => $packId,
            'private_name' => $privateName,
            'normalized_private_name' => $normalizedPrivateName,
            'original_pack_text' => $originalPackText,
            'status' => 'active',
            'revision' => 1,
            'created_at' => $at->format('Y-m-d H:i:s.u'),
            'updated_at' => $at->format('Y-m-d H:i:s.u'),
        ]);
    }
}
