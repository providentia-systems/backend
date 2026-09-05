<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;

interface CatalogImportStore
{
    /**
     * @return array<string, mixed>|null */
    public function findByIdempotency(string $homeId, string $idempotencyKeyHash): ?array;

    /**
     * @return array<string, mixed>|null */
    public function batch(string $homeId, string $batchId): ?array;

    /**
     *
     * @return array{
     *   resolution: 'existing_home'|'global_match'|'no_match'|'error',
     *   homeProductId?: string,
     *   productId?: string,
     *   packId?: string|null,
     *   errorCode?: string,
     *   errorDetail?: string
     * }
     */
    public function resolve(
        string $homeId,
        ?string $productId,
        ?string $packId,
        ?string $barcode,
        string $normalizedName,
        string $normalizedBrand,
        string $normalizedPrivateName,
    ): array;

    /**
     * @param list<array<string, mixed>> $rows */
    public function createBatch(
        string $id,
        string $homeId,
        string $requestedByUserId,
        string $idempotencyKeyHash,
        string $contentHash,
        array $rows,
        DateTimeImmutable $at,
    ): bool;

    /**
     *
     * @return array{
     *   confirmed: bool,
     *   imported: list<array{
     *     id: string,
     *     productId: string|null,
     *     packId: string|null,
     *     privateName: string|null,
     *     originalPackText: string|null
     *   }>
     * }
     */
    public function confirmBatch(
        string $homeId,
        string $batchId,
        int $expectedRevision,
        string $confirmedByUserId,
        DateTimeImmutable $at,
    ): array;
}
