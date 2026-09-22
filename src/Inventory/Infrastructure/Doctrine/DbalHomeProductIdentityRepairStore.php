<?php

declare(strict_types=1);

namespace Providentia\Inventory\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Providentia\Inventory\Application\HomeProductIdentityRepairStore;

final readonly class DbalHomeProductIdentityRepairStore implements HomeProductIdentityRepairStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function scan(string $homeId, ?string $afterId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM home_products WHERE home_id = :home AND id > :after ORDER BY id LIMIT ' . max(1, $limit),
            ['home' => $homeId, 'after' => $afterId ?? ''],
        );

        return array_map(fn (array $row): array => $this->classify($homeId, $row), $rows);
    }

    public function repair(string $homeId, string $id, int $expectedRevision, DateTimeImmutable $at): array
    {
        $lock = $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM home_products WHERE home_id = :home AND id = :id' . $lock,
            ['home' => $homeId, 'id' => $id],
        );
        if ($row === false || (int) $row['revision'] !== $expectedRevision) {
            return ['status' => 'conflict'];
        }
        $candidate = $this->classify($homeId, $row);
        if ($candidate['status'] !== 'repairable') {
            return ['status' => $candidate['status']];
        }
        $changed = $this->connection->executeStatement(
            'UPDATE home_products SET product_id = :product, revision = revision + 1, updated_at = :at
             WHERE home_id = :home AND id = :id AND revision = :revision',
            [
                'product' => $candidate['productId'], 'at' => $at->format('Y-m-d H:i:s.u'),
                'home' => $homeId, 'id' => $id, 'revision' => $expectedRevision,
            ],
        );
        if ($changed !== 1) {
            return ['status' => 'conflict'];
        }

        return [
            'status' => 'updated',
            'revision' => $expectedRevision + 1,
            'representation' => [
                'productId' => $candidate['productId'], 'packId' => $row['pack_id'],
                'privateName' => $row['private_name'], 'productName' => $candidate['productName'],
                'originalPackText' => $row['original_pack_text'],
                'homeCategoryId' => $row['home_category_id'] ?? null,
                'globalCategoryId' => $row['global_category_id'] ?? null,
                'unit' => $row['unit'] ?? 'units', 'status' => $row['status'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function classify(string $homeId, array $row): array
    {
        $product = $row['product_id'];
        $pack = $row['pack_id'];
        $identity = $pack !== null ? 'pack' : ($product !== null ? 'family_without_pack' : 'private');
        $base = ['id' => $row['id'], 'revision' => (int) $row['revision'], 'identity' => $identity];
        if ($pack !== null) {
            $parent = $this->connection->fetchOne(
                'SELECT product_id FROM product_packs WHERE id = :id',
                ['id' => $pack],
            );
            if (! is_string($parent) || ($product !== null && $product !== $parent)) {
                return [...$base, 'status' => 'manual_review'];
            }
            $product = $parent;
        }
        $name = $product === null ? $row['private_name'] : $this->connection->fetchOne(
            'SELECT canonical_name FROM products WHERE id = :id',
            ['id' => $product],
        );
        if ($name === false) {
            return [...$base, 'status' => 'manual_review'];
        }
        $latest = $this->connection->fetchAssociative(
            "SELECT payload_json, operation_type FROM change_log
             WHERE home_id = :home AND entity_type = 'inventory-home-product' AND entity_id = :id
             ORDER BY sequence_id DESC LIMIT 1",
            ['home' => $homeId, 'id' => $row['id']],
        );
        $payload = null;
        if ($latest !== false) {
            try {
                $payload = json_decode((string) $latest['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [...$base, 'status' => 'manual_review'];
            }
            if (! is_array($payload) || $latest['operation_type'] === 'delete') {
                return [...$base, 'status' => 'manual_review'];
            }
        }
        $needsRepair = $row['product_id'] !== $product || $payload === null
            || ($payload['productId'] ?? null) !== $product || ($payload['packId'] ?? null) !== $pack;

        return [
            ...$base, 'status' => $needsRepair ? 'repairable' : 'unchanged',
            'productId' => $product, 'productName' => $name,
        ];
    }
}
