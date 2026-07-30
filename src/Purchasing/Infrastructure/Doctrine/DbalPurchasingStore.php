<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\Purchasing\Application\PurchaseSummaryReader;
use Providentia\Purchasing\Application\PurchasingStore;

final class DbalPurchasingStore implements PurchasingStore, PurchaseSummaryReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function receipts(
        string $homeId,
        ?string $from,
        ?string $to,
        ?string $storeId,
        int $limit,
        int $offset,
    ): array {
        return $this->connection->fetchAllAssociative(
            'SELECT r.id, r.store_id AS storeId, s.name AS storeName,
                    r.purchase_date AS purchaseDate, r.currency,
                    r.total_amount AS totalAmount, r.status, r.source,
                    r.source_reference AS sourceReference, r.notes, r.revision,
                    r.committed_at AS committedAt, r.created_at AS createdAt,
                    COUNT(rl.id) AS lineCount
             FROM receipts r
             LEFT JOIN stores s ON s.id = r.store_id AND s.home_id = r.home_id
             LEFT JOIN receipt_lines rl ON rl.receipt_id = r.id AND rl.home_id = r.home_id
             WHERE r.home_id = :home
               AND (:from_empty = :empty OR r.purchase_date >= :from_date)
               AND (:to_empty = :empty OR r.purchase_date <= :to_date)
               AND (:store_empty = :empty OR r.store_id = :store)
             GROUP BY r.id, r.store_id, s.name, r.purchase_date, r.currency,
                      r.total_amount, r.status, r.source, r.source_reference,
                      r.notes, r.revision, r.committed_at, r.created_at
             ORDER BY r.purchase_date DESC, s.name, r.id
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            [
                'home' => $homeId,
                'from_empty' => $from ?? '',
                'from_date' => $from ?? '',
                'to_empty' => $to ?? '',
                'to_date' => $to ?? '',
                'store_empty' => $storeId ?? '',
                'store' => $storeId ?? '',
                'empty' => '',
            ],
        );
    }

    public function receipt(string $homeId, string $receiptId): ?array
    {
        return $this->one(
            'SELECT r.id, r.home_id AS homeId, r.store_id AS storeId,
                    s.name AS storeName, r.purchase_date AS purchaseDate,
                    r.currency, r.total_amount AS totalAmount, r.status,
                    r.source, r.source_reference AS sourceReference, r.notes,
                    r.revision, r.created_by_user_id AS createdByUserId,
                    r.committed_at AS committedAt, r.created_at AS createdAt,
                    r.updated_at AS updatedAt
             FROM receipts r
             LEFT JOIN stores s ON s.id = r.store_id AND s.home_id = r.home_id
             WHERE r.home_id = :home AND r.id = :id',
            ['home' => $homeId, 'id' => $receiptId],
        );
    }

    public function receiptLines(string $homeId, string $receiptId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT rl.id, rl.line_number AS lineNumber,
                    rl.raw_description AS rawDescription, rl.quantity,
                    rl.original_pack_text AS originalPackText,
                    rl.unit_price AS unitPrice, rl.line_total AS lineTotal,
                    rl.home_product_id AS homeProductId,
                    hp.product_id AS productId, hp.pack_id AS packId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    rl.approval_status AS approvalStatus, rl.revision,
                    rl.created_at AS createdAt, rl.updated_at AS updatedAt
             FROM receipt_lines rl
             LEFT JOIN home_products hp
               ON hp.id = rl.home_product_id AND hp.home_id = rl.home_id
             LEFT JOIN products p ON p.id = hp.product_id
             WHERE rl.home_id = :home AND rl.receipt_id = :receipt
             ORDER BY rl.line_number, rl.id',
            ['home' => $homeId, 'receipt' => $receiptId],
        );
    }

    public function receiptLine(string $homeId, string $receiptId, string $lineId): ?array
    {
        return $this->one(
            'SELECT id, receipt_id AS receiptId, raw_description AS rawDescription,
                    quantity, original_pack_text AS originalPackText,
                    unit_price AS unitPrice, line_total AS lineTotal,
                    home_product_id AS homeProductId,
                    approval_status AS approvalStatus, revision
             FROM receipt_lines
             WHERE home_id = :home AND receipt_id = :receipt AND id = :id',
            ['home' => $homeId, 'receipt' => $receiptId, 'id' => $lineId],
        );
    }

    public function createStore(
        string $id,
        string $homeId,
        string $name,
        string $normalizedName,
        string $location,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $this->connection->insert('stores', [
            'id' => $id,
            'home_id' => $homeId,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'location' => $location,
            'status' => 'active',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function storeByName(string $homeId, string $normalizedName, string $location): ?array
    {
        return $this->one(
            'SELECT id, name, location, status, revision
             FROM stores
             WHERE home_id = :home AND normalized_name = :name
               AND location = :location AND status = :status',
            [
                'home' => $homeId,
                'name' => $normalizedName,
                'location' => $location,
                'status' => 'active',
            ],
        );
    }

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
    ): void {
        if ($storeId !== null) {
            $store = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM stores
                 WHERE id = :store AND home_id = :home AND status = :status',
                ['store' => $storeId, 'home' => $homeId, 'status' => 'active'],
            );
            if ($store !== 1) {
                throw new \DomainException('The selected store is unavailable.');
            }
        }
        $now = $this->date($at);
        $this->connection->insert('receipts', [
            'id' => $id,
            'home_id' => $homeId,
            'store_id' => $storeId,
            'purchase_date' => $purchaseDate,
            'currency' => $currency,
            'total_amount' => $totalAmount,
            'status' => 'draft',
            'source' => $source,
            'source_reference' => $sourceReference,
            'notes' => $notes,
            'revision' => 1,
            'created_by_user_id' => $actorUserId,
            'committed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

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
    ): bool {
        $now = $this->date($at);
        $updated = $this->connection->executeStatement(
            'UPDATE receipts SET revision = revision + 1, updated_at = :updated
             WHERE id = :receipt AND home_id = :home AND status = :status
               AND revision = :revision',
            [
                'updated' => $now,
                'receipt' => $receiptId,
                'home' => $homeId,
                'status' => 'draft',
                'revision' => $expectedReceiptRevision,
            ],
        );
        if ($updated !== 1) {
            return false;
        }
        $this->connection->insert('receipt_lines', [
            'id' => $id,
            'home_id' => $homeId,
            'receipt_id' => $receiptId,
            'line_number' => $lineNumber,
            'raw_description' => $rawDescription,
            'quantity' => $quantity,
            'original_pack_text' => $originalPackText,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'home_product_id' => null,
            'approval_status' => 'unreviewed',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    public function approveReceiptLine(
        string $homeId,
        string $receiptId,
        string $lineId,
        string $homeProductId,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        $product = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM home_products
             WHERE id = :product AND home_id = :home AND status = :status',
            ['product' => $homeProductId, 'home' => $homeId, 'status' => 'active'],
        );
        if ($product !== 1) {
            return false;
        }
        $now = $this->date($at);
        $updated = $this->connection->executeStatement(
            'UPDATE receipt_lines
             SET home_product_id = :product, approval_status = :approved,
                 revision = revision + 1, updated_at = :updated
             WHERE id = :id AND home_id = :home AND receipt_id = :receipt
               AND revision = :revision
               AND EXISTS (
                   SELECT 1 FROM receipts r
                   WHERE r.id = receipt_lines.receipt_id
                     AND r.home_id = receipt_lines.home_id AND r.status = :draft
               )',
            [
                'product' => $homeProductId,
                'approved' => 'approved',
                'updated' => $now,
                'id' => $lineId,
                'home' => $homeId,
                'receipt' => $receiptId,
                'revision' => $expectedRevision,
                'draft' => 'draft',
            ],
        );
        if ($updated !== 1) {
            return false;
        }
        $line = $this->receiptLine($homeId, $receiptId, $lineId);
        if ($line === null) {
            return false;
        }
        $homeProduct = $this->one(
            'SELECT pack_id FROM home_products WHERE id = :id AND home_id = :home',
            ['id' => $homeProductId, 'home' => $homeId],
        );
        if ($homeProduct !== null && $homeProduct['pack_id'] !== null) {
            $existing = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM receipt_line_matches
                 WHERE home_id = :home AND receipt_line_id = :line AND status = :status',
                ['home' => $homeId, 'line' => $lineId, 'status' => 'approved'],
            );
            if ($existing === 0) {
                $this->connection->insert('receipt_line_matches', [
                    'id' => $lineId,
                    'home_id' => $homeId,
                    'receipt_line_id' => $lineId,
                    'product_pack_id' => $homeProduct['pack_id'],
                    'match_method' => 'human-selection',
                    'confidence' => '1',
                    'status' => 'approved',
                    'decided_by_user_id' => $actorUserId,
                    'decided_at' => $now,
                    'created_at' => $now,
                ]);
            }
        }
        $this->connection->executeStatement(
            'UPDATE receipts SET revision = revision + 1, updated_at = :updated
             WHERE id = :receipt AND home_id = :home AND status = :draft',
            ['updated' => $now, 'receipt' => $receiptId, 'home' => $homeId, 'draft' => 'draft'],
        );

        return true;
    }

    public function markReceiptCommitted(
        string $homeId,
        string $receiptId,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool {
        $now = $this->date($at);

        return $this->connection->executeStatement(
            'UPDATE receipts
             SET status = :committed, committed_at = :committed_at,
                 revision = revision + 1, updated_at = :updated
             WHERE id = :id AND home_id = :home AND status = :draft
               AND revision = :revision',
            [
                'committed' => 'committed',
                'committed_at' => $now,
                'updated' => $now,
                'id' => $receiptId,
                'home' => $homeId,
                'draft' => 'draft',
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

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
    ): void {
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM price_observations WHERE receipt_line_id = :line',
            ['line' => $receiptLineId],
        );
        if ($exists > 0) {
            return;
        }
        $this->connection->insert('price_observations', [
            'id' => $id,
            'home_id' => $homeId,
            'receipt_line_id' => $receiptLineId,
            'product_pack_id' => $productPackId,
            'store_id' => $storeId,
            'currency' => $currency,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'observed_at' => $this->date($observedAt),
            'created_at' => $this->date($createdAt),
        ]);
    }

    public function summary(string $homeId, int $recentDays): array
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $recentDays . ' days')
            ->format('Y-m-d');
        $row = $this->one(
            'SELECT COUNT(DISTINCT r.id) AS receiptCount,
                    COUNT(rl.id) AS lineCount,
                    COALESCE(SUM(rl.line_total), 0) AS spend
             FROM receipts r
             LEFT JOIN receipt_lines rl ON rl.receipt_id = r.id AND rl.home_id = r.home_id
             WHERE r.home_id = :home AND r.purchase_date >= :cutoff
               AND r.status = :status',
            ['home' => $homeId, 'cutoff' => $cutoff, 'status' => 'committed'],
        );

        return $row ?? ['receiptCount' => 0, 'lineCount' => 0, 'spend' => '0'];
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $parameters): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $parameters);

        return $row === false ? null : $row;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
