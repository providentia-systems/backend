<?php

declare(strict_types=1);

namespace Providentia\Inventory\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Providentia\Synchronization\Application\SyncRepresentationNormalizer;

/** The selected pack, never a name or the first available pack, determines its parent. */
final readonly class DbalHomeProductSyncNormalizer implements SyncRepresentationNormalizer
{
    public function __construct(private Connection $connection)
    {
    }

    public function normalize(string $homeId, string $entityType, string $entityId, array $payload): array
    {
        if ($entityType !== 'inventory-home-product') {
            return $payload;
        }
        $productId = $payload['productId'] ?? null;
        $packId = $payload['packId'] ?? null;
        if ($productId === null && $packId === null) {
            return $payload;
        }
        if (
            ($productId !== null && (! is_string($productId) || $productId === ''))
            || ($packId !== null && (! is_string($packId) || $packId === ''))
        ) {
            throw new \UnexpectedValueException('Invalid synchronized home-product identity.');
        }
        // Never hydrate a resource merely because another home knows its ID.
        if (
            ! $this->connection->fetchOne(
                'SELECT id FROM home_products WHERE home_id = :home AND id = :id',
                ['home' => $homeId, 'id' => $entityId],
            )
        ) {
            throw new \UnexpectedValueException('The synchronized home product is unavailable.');
        }
        if ($productId === null && $packId !== null) {
            $parent = $this->connection->fetchOne(
                'SELECT product_id FROM product_packs WHERE id = :id',
                ['id' => $packId],
            );
            if (! is_string($parent) || $parent === '') {
                throw new \UnexpectedValueException('The synchronized pack needs an explicit identity repair.');
            }
            $payload['productId'] = $parent;
            $productId = $parent;
        }
        // A family-only record remains family-only. This metadata does not
        // select a pack or alter any balance, count, receipt or source wording.
        if (($payload['productName'] ?? null) === null && $productId !== null) {
            $name = $this->connection->fetchOne(
                'SELECT canonical_name FROM products WHERE id = :id',
                ['id' => $productId],
            );
            if (is_string($name) && $name !== '') {
                $payload['productName'] = $name;
            }
        }

        return $payload;
    }
}
