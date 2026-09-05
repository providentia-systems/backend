<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Providentia\Synchronization\Application\SyncBackfillRecord;
use Providentia\Synchronization\Application\SyncBackfillStore;

final readonly class DbalSyncBackfillStore implements SyncBackfillStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function missingRecords(?string $homeId, int $limit): array
    {
        $remaining = max(1, $limit);
        $records = [];
        foreach ($this->queries() as $entityType => $sql) {
            if ($remaining === 0) {
                break;
            }
            $rows = $this->connection->fetchAllAssociative(
                $sql . ' ORDER BY t.home_id ASC, entity_id ASC LIMIT ' . $remaining,
                ['home_filter' => $homeId === null ? 0 : 1, 'home' => $homeId ?? ''],
            );
            foreach ($rows as $row) {
                $records[] = $this->record($entityType, $row);
            }
            $remaining = $limit - count($records);
        }

        return $records;
    }

    public function hasChange(string $homeId, string $entityType, string $entityId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM change_log
             WHERE home_id = :home AND entity_type = :type AND entity_id = :entity',
            ['home' => $homeId, 'type' => $entityType, 'entity' => $entityId],
        ) > 0;
    }

    public function fallbackActor(string $homeId): string
    {
        $actor = $this->connection->fetchOne(
            "SELECT user_id FROM home_memberships
             WHERE home_id = :home AND status = 'active'
             ORDER BY CASE role
                WHEN 'owner' THEN 0 WHEN 'manager' THEN 1
                WHEN 'member' THEN 2 ELSE 3 END, user_id ASC
             LIMIT 1",
            ['home' => $homeId],
        );
        if (! is_string($actor) || $actor === '') {
            throw new \RuntimeException('A backfilled home has no active actor for change-feed attribution.');
        }

        return $actor;
    }

    /**
     * @return array<string, string> */
    private function queries(): array
    {
        $missing = static fn (string $type): string =>
            " WHERE (:home_filter = 0 OR t.home_id = :home)
              AND NOT EXISTS (
                SELECT 1 FROM change_log c
                WHERE c.home_id = t.home_id
                  AND c.entity_type = '" . $type . "'
                  AND c.entity_id = ";

        return [
            // Dependency order matters for a clean bootstrap: private
            // categories must arrive before products that reference them.
            'inventory-home-category' =>
                'SELECT t.home_id, t.id AS entity_id, t.revision,
                        NULL AS actor_user_id, t.updated_at AS changed_at,
                        t.name, t.status
                 FROM home_categories t'
                . $missing('inventory-home-category') . 't.id)',
            'inventory-home-product' =>
                'SELECT t.home_id, t.id AS entity_id, t.revision,
                        NULL AS actor_user_id, t.updated_at AS changed_at,
                        t.product_id, t.pack_id, t.private_name,
                        t.home_category_id,
                        t.original_pack_text, t.status
                 FROM home_products t'
                . $missing('inventory-home-product') . 't.id)',
            'inventory-balance' =>
                'SELECT t.home_id, t.home_product_id AS entity_id, t.revision,
                        sm.actor_user_id, t.updated_at AS changed_at,
                        t.quantity, t.last_movement_id
                 FROM inventory_balances t
                 LEFT JOIN stock_movements sm ON sm.id = t.last_movement_id'
                . $missing('inventory-balance') . 't.home_product_id)',
            'inventory-count-line' =>
                'SELECT t.home_id, t.id AS entity_id, t.revision,
                        t.counted_by_user_id AS actor_user_id, t.updated_at AS changed_at,
                        t.session_id, t.home_product_id, t.quantity, t.confidence,
                        t.source, t.notes, t.status
                 FROM stock_count_lines t'
                . $missing('inventory-count-line') . 't.id)',
            'inventory-count-session' =>
                'SELECT t.home_id, t.id AS entity_id, t.revision,
                        COALESCE(t.closed_by_user_id, t.opened_by_user_id) AS actor_user_id,
                        t.updated_at AS changed_at, t.location_id, t.notes,
                        t.scope_complete, t.reliability, t.status
                 FROM stock_count_sessions t'
                . $missing('inventory-count-session') . 't.id)',
            'inventory-location' =>
                'SELECT t.home_id, t.id AS entity_id, t.revision,
                        NULL AS actor_user_id, t.updated_at AS changed_at,
                        t.name, t.kind, t.status
                 FROM home_locations t'
                . $missing('inventory-location') . 't.id)',
            'purchasing-receipt' =>
                'SELECT t.home_id, t.id AS entity_id, t.revision,
                        t.created_by_user_id AS actor_user_id, t.updated_at AS changed_at,
                        t.store_id, t.purchase_date, t.currency, t.total_amount,
                        t.status, t.source, t.source_reference, t.notes
                 FROM receipts t'
                . $missing('purchasing-receipt') . 't.id)',
            'purchasing-receipt-line' =>
                'SELECT t.home_id, t.id AS entity_id, t.revision,
                        r.created_by_user_id AS actor_user_id, t.updated_at AS changed_at,
                        t.receipt_id, t.raw_description, t.quantity,
                        t.original_pack_text, t.unit_price, t.line_total,
                        t.home_product_id, t.approval_status
                 FROM receipt_lines t
                 INNER JOIN receipts r ON r.id = t.receipt_id AND r.home_id = t.home_id'
                . $missing('purchasing-receipt-line') . 't.id)',
            'purchasing-store' =>
                'SELECT t.home_id, t.id AS entity_id, t.revision,
                        NULL AS actor_user_id, t.updated_at AS changed_at,
                        t.name, t.location, t.status
                 FROM stores t'
                . $missing('purchasing-store') . 't.id)',
            'shopping-list' =>
                'SELECT t.home_id, t.id AS entity_id, t.revision,
                        t.created_by_user_id AS actor_user_id, t.updated_at AS changed_at,
                        t.name, t.kind, t.status
                 FROM shopping_lists t'
                . $missing('shopping-list') . 't.id)',
            'shopping-list-line' =>
                'SELECT t.home_id, t.id AS entity_id, t.revision,
                        l.created_by_user_id AS actor_user_id, t.updated_at AS changed_at,
                        t.shopping_list_id, t.home_product_id, t.description,
                        t.source, t.quantity_to_buy, t.explanation,
                        t.confidence, t.checked_at
                 FROM shopping_list_lines t
                 INNER JOIN shopping_lists l
                   ON l.id = t.shopping_list_id AND l.home_id = t.home_id'
                . $missing('shopping-list-line') . 't.id)',
        ];
    }

    /**
     * @param array<string, mixed> $row */
    private function record(string $entityType, array $row): SyncBackfillRecord
    {
        $representation = match ($entityType) {
            'inventory-home-category' => [
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
            ],
            'inventory-balance' => [
                'homeProductId' => (string) $row['entity_id'],
                'quantity' => (string) $row['quantity'],
                'lastMovementId' => (string) $row['last_movement_id'],
            ],
            'inventory-count-line' => [
                'sessionId' => (string) $row['session_id'],
                'homeProductId' => (string) $row['home_product_id'],
                'quantity' => (string) $row['quantity'],
                'confidence' => $this->nullableString($row['confidence']),
                'source' => (string) $row['source'],
                'notes' => (string) $row['notes'],
                'status' => (string) $row['status'],
            ],
            'inventory-count-session' => [
                'locationId' => $this->nullableString($row['location_id']),
                'notes' => (string) $row['notes'],
                'scopeComplete' => (bool) $row['scope_complete'],
                'reliability' => (string) $row['reliability'],
                'status' => (string) $row['status'],
            ],
            'inventory-home-product' => [
                'productId' => $this->nullableString($row['product_id']),
                'packId' => $this->nullableString($row['pack_id']),
                'privateName' => $this->nullableString($row['private_name']),
                'originalPackText' => $this->nullableString($row['original_pack_text']),
                'homeCategoryId' => $this->nullableString($row['home_category_id']),
                'status' => (string) $row['status'],
            ],
            'inventory-location' => [
                'name' => (string) $row['name'],
                'kind' => (string) $row['kind'],
                'status' => (string) $row['status'],
            ],
            'purchasing-receipt' => [
                'storeId' => $this->nullableString($row['store_id']),
                'purchaseDate' => (string) $row['purchase_date'],
                'currency' => (string) $row['currency'],
                'totalAmount' => $this->nullableString($row['total_amount']),
                'status' => (string) $row['status'],
                'source' => (string) $row['source'],
                'sourceReference' => $this->nullableString($row['source_reference']),
                'notes' => (string) $row['notes'],
            ],
            'purchasing-receipt-line' => [
                'receiptId' => (string) $row['receipt_id'],
                'rawDescription' => (string) $row['raw_description'],
                'quantity' => (string) $row['quantity'],
                'originalPackText' => $this->nullableString($row['original_pack_text']),
                'unitPrice' => $this->nullableString($row['unit_price']),
                'lineTotal' => $this->nullableString($row['line_total']),
                'homeProductId' => $this->nullableString($row['home_product_id']),
                'approvalStatus' => (string) $row['approval_status'],
            ],
            'purchasing-store' => [
                'name' => (string) $row['name'],
                'location' => (string) $row['location'],
                'status' => (string) $row['status'],
            ],
            'shopping-list' => [
                'name' => (string) $row['name'],
                'kind' => (string) $row['kind'],
                'status' => (string) $row['status'],
            ],
            'shopping-list-line' => [
                'listId' => (string) $row['shopping_list_id'],
                'homeProductId' => $this->nullableString($row['home_product_id']),
                'description' => (string) $row['description'],
                'source' => (string) $row['source'],
                'quantityToBuy' => (string) $row['quantity_to_buy'],
                'explanation' => (string) $row['explanation'],
                'confidence' => $this->nullableString($row['confidence']),
                'checkedAt' => $this->nullableString($row['checked_at']),
                'checked' => $row['checked_at'] !== null,
            ],
            default => throw new \LogicException('Unknown synchronization backfill resource.'),
        };

        return new SyncBackfillRecord(
            (string) $row['home_id'],
            $entityType,
            (string) $row['entity_id'],
            (int) $row['revision'],
            $representation,
            $this->nullableString($row['actor_user_id']),
            new DateTimeImmutable((string) $row['changed_at']),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
