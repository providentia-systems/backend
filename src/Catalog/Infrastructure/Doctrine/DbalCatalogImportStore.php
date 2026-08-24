<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use JsonException;
use Providentia\Catalog\Application\CatalogImportHomeProductGateway;
use Providentia\Catalog\Application\CatalogImportStore;

final readonly class DbalCatalogImportStore implements CatalogImportStore
{
    public function __construct(
        private Connection $connection,
        private CatalogImportHomeProductGateway $homeProducts,
    ) {
    }

    public function findByIdempotency(string $homeId, string $idempotencyKeyHash): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id FROM catalog_import_batches
             WHERE home_id = :home AND idempotency_key_hash = :key_hash',
            ['home' => $homeId, 'key_hash' => $idempotencyKeyHash],
        );

        return $row === false ? null : $this->batch($homeId, (string) $row['id']);
    }

    public function batch(string $homeId, string $batchId): ?array
    {
        $batch = $this->connection->fetchAssociative(
            'SELECT id, home_id AS homeId, requested_by_user_id AS requestedByUserId,
                    content_hash AS contentHash, status, row_count AS rowCount,
                    valid_count AS validCount, error_count AS errorCount,
                    imported_count AS importedCount, skipped_count AS skippedCount,
                    revision, confirmed_by_user_id AS confirmedByUserId,
                    confirmed_at AS confirmedAt, created_at AS createdAt, updated_at AS updatedAt
             FROM catalog_import_batches
             WHERE id = :id AND home_id = :home',
            ['id' => $batchId, 'home' => $homeId],
        );
        if ($batch === false) {
            return null;
        }
        foreach (['rowCount', 'validCount', 'errorCount', 'importedCount', 'skippedCount', 'revision'] as $key) {
            $batch[$key] = (int) $batch[$key];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT position, record_type AS recordType, payload_json AS payloadJson,
                    resolution, target_home_product_id AS targetHomeProductId,
                    matched_home_product_id AS matchedHomeProductId,
                    product_id AS productId, pack_id AS packId, private_name AS privateName,
                    original_pack_text AS packText, error_code AS errorCode, error_detail AS errorDetail
             FROM catalog_import_rows
             WHERE batch_id = :batch
             ORDER BY position',
            ['batch' => $batchId],
        );
        foreach ($rows as &$row) {
            $row['position'] = (int) $row['position'];
            try {
                $row['record'] = json_decode((string) $row['payloadJson'], true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new \RuntimeException('A stored catalog import row is not valid JSON.');
            }
            unset($row['payloadJson']);
        }
        unset($row);
        $batch['rows'] = $rows;

        return $batch;
    }

    public function resolve(
        string $homeId,
        ?string $productId,
        ?string $packId,
        ?string $barcode,
        string $normalizedName,
        string $normalizedBrand,
        string $normalizedPrivateName,
    ): array {
        $resolvedProduct = null;
        $resolvedPack = null;
        if ($packId !== null) {
            $pack = $this->connection->fetchAssociative(
                'SELECT pk.id AS packId, pk.product_id AS productId
                 FROM product_packs pk
                 INNER JOIN products p ON p.id = pk.product_id
                 WHERE pk.id = :pack AND pk.status <> :archived AND p.status = :published',
                ['pack' => $packId, 'archived' => 'archived', 'published' => 'published'],
            );
            if ($pack === false) {
                return $this->resolutionError(
                    'catalog_pack_not_found',
                    'The selected published catalog pack is unavailable.',
                );
            }
            $resolvedPack = (string) $pack['packId'];
            $resolvedProduct = (string) $pack['productId'];
        }
        if ($barcode !== null) {
            $barcodeMatch = $this->connection->fetchAssociative(
                'SELECT pk.id AS packId, pk.product_id AS productId
                 FROM product_barcodes b
                 INNER JOIN product_packs pk ON pk.id = b.pack_id
                 INNER JOIN products p ON p.id = pk.product_id
                 WHERE b.barcode = :barcode AND b.status <> :archived
                   AND pk.status <> :archived AND p.status = :published',
                ['barcode' => $barcode, 'archived' => 'archived', 'published' => 'published'],
            );
            if ($barcodeMatch === false) {
                return $this->resolutionError(
                    'barcode_not_found',
                    'The barcode does not identify a published catalog pack.',
                );
            }
            if (
                ($resolvedPack !== null && $resolvedPack !== (string) $barcodeMatch['packId'])
                || ($resolvedProduct !== null && $resolvedProduct !== (string) $barcodeMatch['productId'])
            ) {
                return $this->resolutionError(
                    'identifier_conflict',
                    'The supplied catalog identifiers refer to different products.',
                );
            }
            $resolvedPack = (string) $barcodeMatch['packId'];
            $resolvedProduct = (string) $barcodeMatch['productId'];
        }
        if ($productId !== null) {
            $product = $this->connection->fetchAssociative(
                'SELECT id FROM products WHERE id = :product AND status = :published',
                ['product' => $productId, 'published' => 'published'],
            );
            if ($product === false) {
                return $this->resolutionError(
                    'catalog_product_not_found',
                    'The selected published catalog product is unavailable.',
                );
            }
            if ($resolvedProduct !== null && $resolvedProduct !== $productId) {
                return $this->resolutionError(
                    'identifier_conflict',
                    'The supplied catalog identifiers refer to different products.',
                );
            }
            $resolvedProduct = $productId;
        }
        if ($resolvedProduct === null && $normalizedName !== '') {
            $parameters = ['name' => $normalizedName, 'published' => 'published'];
            $brandSql = '';
            if ($normalizedBrand !== '') {
                $brandSql = ' AND normalized_brand = :brand';
                $parameters['brand'] = $normalizedBrand;
            }
            $matches = $this->connection->fetchAllAssociative(
                'SELECT id FROM products
                 WHERE normalized_name = :name AND status = :published' . $brandSql . '
                 ORDER BY id LIMIT 2',
                $parameters,
            );
            if (count($matches) > 1) {
                return $this->resolutionError(
                    'ambiguous_global_identity',
                    'More than one published catalog product matches the normalized name; add a brand or identifier.',
                );
            }
            if ($matches !== []) {
                $resolvedProduct = (string) $matches[0]['id'];
            }
        }

        if ($resolvedProduct !== null) {
            $existingId = $this->homeProducts->matchingActiveId(
                $homeId,
                $resolvedProduct,
                $resolvedPack,
                null,
            );
            if ($existingId !== null) {
                return [
                    'resolution' => 'existing_home',
                    'homeProductId' => $existingId,
                    'productId' => $resolvedProduct,
                    'packId' => $resolvedPack,
                ];
            }

            return ['resolution' => 'global_match', 'productId' => $resolvedProduct, 'packId' => $resolvedPack];
        }

        if ($normalizedPrivateName !== '') {
            $existingId = $this->homeProducts->matchingActiveId(
                $homeId,
                null,
                null,
                $normalizedPrivateName,
            );
            if ($existingId !== null) {
                return ['resolution' => 'existing_home', 'homeProductId' => $existingId];
            }
        }

        return ['resolution' => 'no_match'];
    }

    public function createBatch(
        string $id,
        string $homeId,
        string $requestedByUserId,
        string $idempotencyKeyHash,
        string $contentHash,
        array $rows,
        DateTimeImmutable $at,
    ): bool {
        try {
            $this->connection->insert('catalog_import_home_locks', ['home_id' => $homeId, 'revision' => 0]);
        } catch (UniqueConstraintViolationException) {
            // The per-home serialization row is intentionally shared by all batches.
        }
        $validCount = count(array_filter($rows, static fn (array $row): bool => $row['errorCode'] === null));
        try {
            $this->connection->insert('catalog_import_batches', [
                'id' => $id,
                'home_id' => $homeId,
                'requested_by_user_id' => $requestedByUserId,
                'idempotency_key_hash' => $idempotencyKeyHash,
                'content_hash' => $contentHash,
                'status' => 'staged',
                'row_count' => count($rows),
                'valid_count' => $validCount,
                'error_count' => count($rows) - $validCount,
                'imported_count' => 0,
                'skipped_count' => 0,
                'revision' => 1,
                'confirmed_by_user_id' => null,
                'confirmed_at' => null,
                'created_at' => $this->date($at),
                'updated_at' => $this->date($at),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
        foreach ($rows as $row) {
            $this->connection->insert('catalog_import_rows', [
                'batch_id' => $id,
                'position' => $row['position'],
                'record_type' => $row['recordType'],
                'payload_json' => json_encode($row['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'resolution' => $row['resolution'],
                'target_home_product_id' => $row['targetHomeProductId'],
                'matched_home_product_id' => $row['matchedHomeProductId'],
                'product_id' => $row['productId'],
                'pack_id' => $row['packId'],
                'private_name' => $row['privateName'],
                'normalized_private_name' => $row['normalizedPrivateName'],
                'original_pack_text' => $row['packText'],
                'deduplication_key' => $row['deduplicationKey'],
                'error_code' => $row['errorCode'],
                'error_detail' => $row['errorDetail'],
                'created_at' => $this->date($at),
            ]);
        }

        return true;
    }

    public function confirmBatch(
        string $homeId,
        string $batchId,
        int $expectedRevision,
        string $confirmedByUserId,
        DateTimeImmutable $at,
    ): array {
        $this->connection->executeStatement(
            'UPDATE catalog_import_home_locks SET revision = revision + 1 WHERE home_id = :home',
            ['home' => $homeId],
        );
        $claimed = $this->connection->update('catalog_import_batches', [
            'status' => 'confirming',
            'updated_at' => $this->date($at),
        ], [
            'id' => $batchId,
            'home_id' => $homeId,
            'status' => 'staged',
            'revision' => $expectedRevision,
        ]);
        if ($claimed !== 1) {
            return ['confirmed' => false, 'imported' => []];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT target_home_product_id AS targetHomeProductId, resolution,
                    product_id AS productId, pack_id AS packId, private_name AS privateName,
                    normalized_private_name AS normalizedPrivateName,
                    original_pack_text AS packText
             FROM catalog_import_rows
             WHERE batch_id = :batch AND resolution IN (:link_catalog, :create_private)
             ORDER BY position',
            ['batch' => $batchId, 'link_catalog' => 'link_catalog', 'create_private' => 'create_private'],
        );
        $imported = 0;
        $skipped = 0;
        $importedRecords = [];
        foreach ($rows as $row) {
            $productId = $row['productId'] === null ? null : (string) $row['productId'];
            $packId = $row['packId'] === null ? null : (string) $row['packId'];
            $normalizedPrivateName = $row['normalizedPrivateName'] === null
                ? null
                : (string) $row['normalizedPrivateName'];
            if ($this->homeProductExists($homeId, $productId, $packId, $normalizedPrivateName)) {
                ++$skipped;
                continue;
            }
            if ($productId !== null && ! $this->publishedTargetExists($productId, $packId)) {
                throw new \DomainException('A staged catalog target is no longer published.');
            }
            $this->homeProducts->create(
                (string) $row['targetHomeProductId'],
                $homeId,
                $productId,
                $packId,
                $row['privateName'] === null ? null : (string) $row['privateName'],
                $normalizedPrivateName,
                $row['packText'] === null ? null : (string) $row['packText'],
                $at,
            );
            $importedRecords[] = [
                'id' => (string) $row['targetHomeProductId'],
                'productId' => $productId,
                'packId' => $packId,
                'privateName' => $row['privateName'] === null ? null : (string) $row['privateName'],
                'originalPackText' => $row['packText'] === null ? null : (string) $row['packText'],
            ];
            ++$imported;
        }
        $skipped += (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM catalog_import_rows
             WHERE batch_id = :batch AND resolution = :already_present',
            ['batch' => $batchId, 'already_present' => 'already_present'],
        );
        $updated = $this->connection->update('catalog_import_batches', [
            'status' => 'confirmed',
            'imported_count' => $imported,
            'skipped_count' => $skipped,
            'revision' => $expectedRevision + 1,
            'confirmed_by_user_id' => $confirmedByUserId,
            'confirmed_at' => $this->date($at),
            'updated_at' => $this->date($at),
        ], [
            'id' => $batchId,
            'home_id' => $homeId,
            'status' => 'confirming',
            'revision' => $expectedRevision,
        ]);

        if ($updated !== 1) {
            throw new \DomainException('The staged catalog import changed during confirmation.');
        }

        return ['confirmed' => true, 'imported' => $importedRecords];
    }

    private function homeProductExists(
        string $homeId,
        ?string $productId,
        ?string $packId,
        ?string $normalizedPrivateName,
    ): bool {
        return $this->homeProducts->matchingActiveId(
            $homeId,
            $productId,
            $packId,
            $normalizedPrivateName,
        ) !== null;
    }

    private function publishedTargetExists(string $productId, ?string $packId): bool
    {
        if ($packId === null) {
            return $this->connection->fetchOne(
                'SELECT id FROM products WHERE id = :product AND status = :published',
                ['product' => $productId, 'published' => 'published'],
            ) !== false;
        }

        return $this->connection->fetchOne(
            'SELECT pk.id FROM product_packs pk
             INNER JOIN products p ON p.id = pk.product_id
             WHERE pk.id = :pack AND pk.product_id = :product
               AND pk.status <> :archived AND p.status = :published',
            ['pack' => $packId, 'product' => $productId, 'archived' => 'archived', 'published' => 'published'],
        ) !== false;
    }

    /** @return array{resolution: 'error', errorCode: string, errorDetail: string} */
    private function resolutionError(string $code, string $detail): array
    {
        return ['resolution' => 'error', 'errorCode' => $code, 'errorDetail' => $detail];
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }
}
