<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Application;

use DateTimeImmutable;

interface PurchasingStore
{
    public function homeTimezone(string $homeId): ?string;

    /** @return list<array<string, mixed>> */
    public function receipts(
        string $homeId,
        ?string $from,
        ?string $to,
        ?string $storeId,
        int $limit,
        int $offset,
    ): array;

    /** @return array<string, mixed>|null */
    public function receipt(string $homeId, string $receiptId): ?array;

    /** @return list<array<string, mixed>> */
    public function receiptLineCorrections(string $homeId, string $receiptId): array;

    /** @return array<string, mixed>|null */
    public function receiptLineCorrection(string $homeId, string $receiptId, string $correctionId): ?array;

    /** @param array<string, mixed> $fields */
    public function completeReceiptLine(
        string $homeId,
        string $receiptId,
        string $lineId,
        int $expectedReceiptRevision,
        ?int $expectedLineRevision,
        array $fields,
        DateTimeImmutable $at,
    ): bool;

    /**
     * @param array<string, mixed>|null $originalLine
     * @param array<string, mixed> $nextLine
     */
    public function recordReceiptLineCorrection(
        string $id,
        string $homeId,
        string $receiptId,
        string $lineId,
        string $requestHash,
        string $reason,
        int $receiptRevision,
        ?array $originalLine,
        array $nextLine,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void;

    /** @return list<array<string, mixed>> */
    public function receiptDateCorrections(string $homeId, string $receiptId): array;

    public function correctReceiptDate(
        string $id,
        string $homeId,
        string $receiptId,
        string $originalDate,
        string $purchaseDate,
        string $reason,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    /** @return list<array<string, mixed>> */
    public function receiptLines(string $homeId, string $receiptId): array;

    /** @return array<string, mixed>|null */
    public function receiptLine(string $homeId, string $receiptId, string $lineId): ?array;

    /** @return list<array<string, mixed>> */
    public function stores(string $homeId, bool $includeArchived = false): array;

    /** @return array<string, mixed>|null */
    public function store(string $homeId, string $storeId): ?array;

    /**
     * @return array{status: 'updated', record: array<string, mixed>}
     *     |array{status: 'not-found'|'revision-conflict'|'store-in-use'}
     */
    public function updateStore(
        string $homeId,
        string $storeId,
        ?string $name,
        ?string $normalizedName,
        ?string $location,
        ?string $status,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): array;

    public function createStore(
        string $id,
        string $homeId,
        string $name,
        string $normalizedName,
        string $location,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, mixed>|null */
    public function storeByName(string $homeId, string $normalizedName, string $location): ?array;

    public function createReceipt(
        string $id,
        string $homeId,
        ?string $storeId,
        string $purchaseDate,
        string $currency,
        ?string $totalAmount,
        string $source,
        ?string $sourceReference,
        string $notes,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void;

    /** @param array<string, mixed> $fields */
    public function updateDraftReceipt(
        string $homeId,
        string $receiptId,
        array $fields,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    /** @param array<string, mixed> $fields */
    public function updateDraftReceiptLine(
        string $homeId,
        string $receiptId,
        string $lineId,
        array $fields,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    /** @param array<string, mixed> $purchase */
    public function addReceiptLine(
        string $id,
        string $homeId,
        string $receiptId,
        int $expectedReceiptRevision,
        int $lineNumber,
        string $rawDescription,
        string $quantity,
        ?string $originalPackText,
        ?string $unitPrice,
        ?string $lineTotal,
        DateTimeImmutable $at,
        array $purchase = [],
    ): bool;

    /** @return array<string, mixed>|null */
    public function purchaseProduct(string $homeId, string $homeProductId, ?string $packId): ?array;

    /** @param array<string, mixed> $fields */
    public function applyReceiptLine(
        string $homeId,
        string $receiptId,
        string $lineId,
        array $fields,
        int $expectedRevision,
        DateTimeImmutable $at,
        bool $bumpReceipt = true,
    ): bool;

    /** @param array<string, mixed> $fields */
    public function updateReceiptMetadata(
        string $homeId,
        string $receiptId,
        array $fields,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    /** @param array<string, mixed> $pack */
    public function approveReceiptLine(
        string $matchId,
        string $homeId,
        string $receiptId,
        string $lineId,
        string $homeProductId,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
        array $pack = [],
    ): bool;

    public function markReceiptLineUnresolved(
        string $homeId,
        string $receiptId,
        string $lineId,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    public function markReceiptCommitted(
        string $homeId,
        string $receiptId,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    public function recordPriceObservation(
        string $id,
        string $homeId,
        string $receiptLineId,
        ?string $productPackId,
        ?string $storeId,
        string $currency,
        string $quantity,
        ?string $unitPrice,
        string $lineTotal,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $createdAt,
    ): void;

    /** @return array<string, mixed> */
    public function summary(string $homeId, int $recentDays): array;
}
