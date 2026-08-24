<?php

declare(strict_types=1);

namespace Providentia\Inventory\Application;

use DateTimeImmutable;

interface InventoryStore
{
    /** @return list<array<string, mixed>> */
    public function categories(string $homeId, bool $includeArchived): array;

    public function createHomeCategory(
        string $id,
        string $homeId,
        string $name,
        string $normalizedName,
        DateTimeImmutable $at,
    ): void;

    /**
     * @return array{status: 'updated'|'not-found'|'revision-conflict'|'category-in-use', record?: array<string, mixed>}
     */
    public function updateHomeCategory(
        string $homeId,
        string $categoryId,
        ?string $name,
        ?string $normalizedName,
        ?string $status,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): array;

    /** @return list<array<string, mixed>> */
    public function locations(string $homeId): array;

    public function createLocation(
        string $id,
        string $homeId,
        string $name,
        string $normalizedName,
        string $kind,
        DateTimeImmutable $at,
    ): void;

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function itemMaster(
        string $homeId,
        string $query,
        ?string $categoryId,
        ?string $homeCategoryId,
        int $limit,
        int $offset,
    ): array;

    /** @return list<array<string, mixed>> */
    public function stock(
        string $homeId,
        string $query,
        ?string $categoryId,
        ?string $homeCategoryId,
        int $limit,
        int $offset,
    ): array;

    /** @return array<string, mixed>|null */
    public function homeProduct(string $homeId, string $homeProductId): ?array;

    public function createHomeProduct(
        string $id,
        string $homeId,
        ?string $productId,
        ?string $packId,
        ?string $privateName,
        ?string $normalizedPrivateName,
        ?string $originalPackText,
        ?string $homeCategoryId,
        DateTimeImmutable $at,
    ): void;

    /**
     * @return array{
     *     status: 'updated'|'not-found'|'revision-conflict'|'category-unavailable'
     *         |'balance-not-zero'|'product-in-use'|'catalog-product',
     *     record?: array<string, mixed>
     * }
     */
    public function updateHomeProduct(
        string $homeId,
        string $homeProductId,
        bool $privateNameProvided,
        ?string $privateName,
        ?string $normalizedPrivateName,
        bool $originalPackTextProvided,
        ?string $originalPackText,
        bool $homeCategoryProvided,
        ?string $homeCategoryId,
        ?string $status,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): array;

    /** @return array<string, mixed> */
    public function appendMovement(
        string $id,
        string $homeId,
        string $homeProductId,
        string $movementType,
        string $quantityDelta,
        string $sourceType,
        string $sourceId,
        string $reason,
        string $actorUserId,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $recordedAt,
    ): array;

    /** @return list<array<string, mixed>> */
    public function movements(string $homeId, ?string $homeProductId, int $limit, int $offset): array;

    /** @return array<string, mixed>|null */
    public function balance(string $homeId, string $homeProductId): ?array;

    public function createCountSession(
        string $id,
        string $homeId,
        ?string $locationId,
        string $notes,
        bool $scopeComplete,
        string $reliability,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, mixed>|null */
    public function countSession(string $homeId, string $sessionId): ?array;

    /** @return list<array<string, mixed>> */
    public function countSessions(string $homeId, int $limit, int $offset): array;

    /** @return list<array<string, mixed>> */
    public function countLines(string $homeId, string $sessionId): array;

    public function saveCountLine(
        string $id,
        string $homeId,
        string $sessionId,
        string $homeProductId,
        string $quantity,
        ?string $confidence,
        string $source,
        string $notes,
        string $actorUserId,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    public function closeCountSession(
        string $homeId,
        string $sessionId,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    public function cancelCountSession(
        string $homeId,
        string $sessionId,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    /** @return array{products: int, quantity: string} */
    public function rebuildBalances(string $homeId, DateTimeImmutable $at): array;
}
