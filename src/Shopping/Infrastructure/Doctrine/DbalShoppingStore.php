<?php

declare(strict_types=1);

namespace Providentia\Shopping\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\Shopping\Application\ShoppingStore;
use Providentia\Shopping\Application\ShoppingSummaryReader;

final class DbalShoppingStore implements ShoppingStore, ShoppingSummaryReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function lists(string $homeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT sl.id, sl.name, sl.kind, sl.status, sl.revision,
                    sl.created_by_user_id AS createdByUserId,
                    sl.created_at AS createdAt, sl.updated_at AS updatedAt,
                    COUNT(sll.id) AS lineCount,
                    SUM(CASE WHEN sll.checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checkedCount
             FROM shopping_lists sl
             LEFT JOIN shopping_list_lines sll
               ON sll.shopping_list_id = sl.id AND sll.home_id = sl.home_id
             WHERE sl.home_id = :home AND sl.status <> :archived
             GROUP BY sl.id, sl.name, sl.kind, sl.status, sl.revision,
                      sl.created_by_user_id, sl.created_at, sl.updated_at
             ORDER BY sl.updated_at DESC, sl.id',
            ['home' => $homeId, 'archived' => 'archived'],
        );
    }

    public function shoppingList(string $homeId, string $listId): ?array
    {
        return $this->one(
            'SELECT id, name, kind, status, revision,
                    created_by_user_id AS createdByUserId,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM shopping_lists WHERE home_id = :home AND id = :id',
            ['home' => $homeId, 'id' => $listId],
        );
    }

    public function lines(string $homeId, string $listId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT sll.id, sll.home_product_id AS homeProductId,
                    COALESCE(p.canonical_name, hp.private_name, sll.description) AS productName,
                    sll.description, sll.source, sll.quantity_to_buy AS quantityToBuy,
                    sll.explanation, sll.confidence, sll.checked_at AS checkedAt,
                    sll.revision, sll.created_at AS createdAt, sll.updated_at AS updatedAt
             FROM shopping_list_lines sll
             LEFT JOIN home_products hp
               ON hp.id = sll.home_product_id AND hp.home_id = sll.home_id
             LEFT JOIN products p ON p.id = hp.product_id
             WHERE sll.home_id = :home AND sll.shopping_list_id = :list
             ORDER BY CASE WHEN sll.checked_at IS NULL THEN 0 ELSE 1 END,
                      productName, sll.id',
            ['home' => $homeId, 'list' => $listId],
        );
    }

    public function createList(
        string $id,
        string $homeId,
        string $name,
        string $kind,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $this->connection->insert('shopping_lists', [
            'id' => $id,
            'home_id' => $homeId,
            'name' => $name,
            'kind' => $kind,
            'status' => 'open',
            'revision' => 1,
            'created_by_user_id' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function addLine(
        string $id,
        string $homeId,
        string $listId,
        int $expectedListRevision,
        ?string $homeProductId,
        string $description,
        string $source,
        string $quantity,
        string $explanation,
        ?string $confidence,
        DateTimeImmutable $at,
    ): bool {
        if ($homeProductId !== null) {
            $product = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM home_products
                 WHERE id = :product AND home_id = :home AND status = :status',
                ['product' => $homeProductId, 'home' => $homeId, 'status' => 'active'],
            );
            if ($product !== 1) {
                throw new \DomainException('The selected home product is unavailable.');
            }
        }
        $now = $this->date($at);
        $updated = $this->connection->executeStatement(
            'UPDATE shopping_lists SET revision = revision + 1, updated_at = :updated
             WHERE id = :list AND home_id = :home AND status = :status
               AND revision = :revision',
            [
                'updated' => $now,
                'list' => $listId,
                'home' => $homeId,
                'status' => 'open',
                'revision' => $expectedListRevision,
            ],
        );
        if ($updated !== 1) {
            return false;
        }
        $this->connection->insert('shopping_list_lines', [
            'id' => $id,
            'home_id' => $homeId,
            'shopping_list_id' => $listId,
            'home_product_id' => $homeProductId,
            'description' => $description,
            'source' => $source,
            'quantity_to_buy' => $quantity,
            'explanation' => $explanation,
            'confidence' => $confidence,
            'checked_at' => null,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    public function setChecked(
        string $homeId,
        string $listId,
        string $lineId,
        bool $checked,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool {
        $now = $this->date($at);
        $updated = $this->connection->executeStatement(
            'UPDATE shopping_list_lines
             SET checked_at = :checked, revision = revision + 1, updated_at = :updated
             WHERE id = :id AND home_id = :home AND shopping_list_id = :list
               AND revision = :revision
               AND EXISTS (
                   SELECT 1 FROM shopping_lists sl
                   WHERE sl.id = shopping_list_lines.shopping_list_id
                     AND sl.home_id = shopping_list_lines.home_id AND sl.status = :status
               )',
            [
                'checked' => $checked ? $now : null,
                'updated' => $now,
                'id' => $lineId,
                'home' => $homeId,
                'list' => $listId,
                'revision' => $expectedRevision,
                'status' => 'open',
            ],
        );
        if ($updated !== 1) {
            return false;
        }
        $this->connection->executeStatement(
            'UPDATE shopping_lists SET revision = revision + 1, updated_at = :updated
             WHERE id = :list AND home_id = :home AND status = :status',
            ['updated' => $now, 'list' => $listId, 'home' => $homeId, 'status' => 'open'],
        );

        return true;
    }

    public function legacySuggestionCandidates(string $homeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT hp.id AS homeProductId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    COALESCE(pk.original_pack_text, hp.original_pack_text, :empty) AS packText,
                    COALESCE(ib.quantity, 0) AS currentQuantity,
                    COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN rl.quantity ELSE 0 END), 0)
                        AS threeMonthPurchases,
                    COALESCE(stp.never_suggest, 0) AS neverSuggest
             FROM home_products hp
             LEFT JOIN products p ON p.id = hp.product_id
             LEFT JOIN product_packs pk ON pk.id = hp.pack_id
             LEFT JOIN inventory_balances ib
               ON ib.home_id = hp.home_id AND ib.home_product_id = hp.id
             LEFT JOIN stock_threshold_preferences stp
               ON stp.home_id = hp.home_id AND stp.home_product_id = hp.id
             LEFT JOIN receipt_lines rl
               ON rl.home_id = hp.home_id AND rl.home_product_id = hp.id
             LEFT JOIN receipts r
               ON r.id = rl.receipt_id AND r.home_id = rl.home_id
              AND r.source = :source AND r.purchase_date >= :from_date
              AND r.purchase_date <= :to_date AND r.status = :committed
             WHERE hp.home_id = :home AND hp.status = :active
             GROUP BY hp.id, p.canonical_name, hp.private_name,
                      pk.original_pack_text, hp.original_pack_text,
                      ib.quantity, stp.never_suggest
             HAVING COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN rl.quantity ELSE 0 END), 0) > 0
             ORDER BY productName, packText, hp.id',
            [
                'empty' => '',
                'source' => 'baseline-history',
                'from_date' => '2026-04-01',
                'to_date' => '2026-06-30',
                'committed' => 'committed',
                'home' => $homeId,
                'active' => 'active',
            ],
        );
    }

    public function shoppingSummary(string $homeId): array
    {
        return $this->one(
            'SELECT COUNT(DISTINCT sl.id) AS openListCount,
                    COUNT(sll.id) AS lineCount,
                    SUM(CASE WHEN sll.checked_at IS NULL THEN 1 ELSE 0 END) AS uncheckedLineCount
             FROM shopping_lists sl
             LEFT JOIN shopping_list_lines sll
               ON sll.shopping_list_id = sl.id AND sll.home_id = sl.home_id
             WHERE sl.home_id = :home AND sl.status = :status',
            ['home' => $homeId, 'status' => 'open'],
        ) ?? ['openListCount' => 0, 'lineCount' => 0, 'uncheckedLineCount' => 0];
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
