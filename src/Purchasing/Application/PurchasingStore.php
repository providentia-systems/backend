<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Application;

use DateTimeImmutable;

interface PurchasingStore
{
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
    public function receiptLines(string $homeId, string $receiptId): array;

    /** @return array<string, mixed>|null */
    public function receiptLine(string $homeId, string $receiptId, string $lineId): ?array;

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
    ): bool;

    public function approveReceiptLine(
        string $homeId,
        string $receiptId,
        string $lineId,
        string $homeProductId,
        int $expectedRevision,
        string $actorUserId,
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
