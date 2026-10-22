<?php

declare(strict_types=1);

namespace Providentia\Shopping\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\Inventory\Domain\DecimalQuantity;
use Providentia\SharedKernel\Infrastructure\Doctrine\DecimalSql;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Providentia\SharedKernel\Infrastructure\Doctrine\DecimalProjection;
use Providentia\Shopping\Application\ShoppingStore;
use Providentia\Shopping\Application\ShoppingSummaryReader;

final class DbalShoppingStore implements ShoppingStore, ShoppingSummaryReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function lists(string $homeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT sl.id, sl.home_id AS homeId, sl.name, sl.kind, sl.status, sl.revision,
                    sl.created_by_user_id AS createdByUserId,
                    sl.created_at AS createdAt, sl.updated_at AS updatedAt,
                    COUNT(sll.id) AS lineCount,
                    SUM(CASE WHEN sll.checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checkedCount
             FROM shopping_lists sl
             LEFT JOIN shopping_list_lines sll
               ON sll.shopping_list_id = sl.id AND sll.home_id = sl.home_id AND sll.archived_at IS NULL
             WHERE sl.home_id = :home
             GROUP BY sl.id, sl.home_id, sl.name, sl.kind, sl.status, sl.revision,
                      sl.created_by_user_id, sl.created_at, sl.updated_at
             ORDER BY sl.updated_at DESC, sl.id',
            ['home' => $homeId],
        );
        return array_map($this->listRecord(...), $rows);
    }

    public function shoppingList(string $homeId, string $listId): ?array
    {
        $row = $this->one(
            'SELECT id, home_id AS homeId, name, kind, status, revision,
                    created_by_user_id AS createdByUserId,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM shopping_lists WHERE home_id = :home AND id = :id',
            ['home' => $homeId, 'id' => $listId],
        );
        return $row === null ? null : $this->listRecord($row);
    }

    public function lines(string $homeId, string $listId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT sll.id, sll.home_product_id AS homeProductId,
                    hp.product_id AS productId, hp.unit AS stockUnit,
                    COALESCE(hp.private_name, p.canonical_name, sll.description) AS productName,
                    sll.description, sll.source, sll.quantity_to_buy AS quantityToBuy,
                    sll.explanation, sll.confidence, sll.checked_at AS checkedAt,
                    sll.archived_at AS archivedAt, sll.suggestion_id AS suggestionId,
                    sll.selected_pack_id AS selectedPackId,
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
        return array_map($this->lineRecord(...), $rows);
    }

    public function line(string $homeId, string $listId, string $lineId): ?array
    {
        $row = $this->one(
            'SELECT sll.id, sll.shopping_list_id AS listId, sll.home_product_id AS homeProductId,
                    hp.product_id AS productId, hp.unit AS stockUnit,
                    sll.description, sll.source, sll.quantity_to_buy AS quantityToBuy, sll.explanation, sll.confidence,
                    sll.checked_at AS checkedAt, sll.archived_at AS archivedAt, sll.revision,
                    sll.suggestion_id AS suggestionId, sll.selected_pack_id AS selectedPackId
             FROM shopping_list_lines sll LEFT JOIN home_products hp
               ON hp.id = sll.home_product_id AND hp.home_id = sll.home_id
             WHERE sll.home_id = :home AND sll.shopping_list_id = :list AND sll.id = :id',
            ['home' => $homeId, 'list' => $listId, 'id' => $lineId],
        );
        return $row === null ? null : $this->lineRecord($row);
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
        ?string $suggestionId = null,
        ?string $selectedPackId = null,
    ): bool {
        $this->validateSelection($homeId, $homeProductId, $selectedPackId);
        if ($suggestionId !== null) {
            $suggestion = $this->connection->fetchAssociative(
                'SELECT home_product_id, selected_pack_id, confidence_band, status, expires_at
                 FROM shopping_suggestions WHERE home_id = :home AND id = :suggestion
                   AND run_id = (SELECT id FROM shopping_suggestion_runs
                       WHERE home_id = :home AND status = :completed
                       ORDER BY as_of DESC, id DESC LIMIT 1)',
                ['home' => $homeId, 'suggestion' => $suggestionId, 'completed' => 'completed'],
            );
            if (
                $suggestion === false ||
                $suggestion['home_product_id'] !== $homeProductId ||
                $suggestion['status'] !== 'active' ||
                $suggestion['expires_at'] <= $this->date($at)
            ) {
                throw new \DomainException('The recommendation is no longer available for this home product.');
            }
            if ($selectedPackId !== null && $selectedPackId !== $suggestion['selected_pack_id']) {
                throw new \DomainException('The selected pack does not match this recommendation.');
            }
            $selectedPackId = $suggestion['selected_pack_id'];
            $source = 'suggested';
            $confidence = (string) $suggestion['confidence_band'];
            $explanation = 'Suggested from household consumption and stock evidence.';
        }
        $this->validateSelection($homeId, $homeProductId, $selectedPackId);
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
            'suggestion_id' => $suggestionId,
            'selected_pack_id' => $selectedPackId,
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

    public function relinkLine(
        string $homeId,
        string $listId,
        string $lineId,
        ?string $homeProductId,
        ?string $selectedPackId,
        int $expectedRevision,
        string $actorUserId,
        string $auditEventId,
        string $reason,
        DateTimeImmutable $at,
    ): bool {
        if (! $this->lockOpenList($homeId, $listId)) {
            return false;
        }
        $before = $this->line($homeId, $listId, $lineId);
        if ($before === null || $before['revision'] !== $expectedRevision) {
            return false;
        }
        $this->validateSelection($homeId, $homeProductId, $selectedPackId);
        $now = $this->date($at);
        $updated = $this->connection->executeStatement(
            'UPDATE shopping_list_lines
             SET home_product_id = :product, selected_pack_id = :pack, source = :source,
                 suggestion_id = NULL, confidence = NULL, explanation = :explanation,
                 revision = revision + 1, updated_at = :updated
             WHERE home_id = :home AND shopping_list_id = :list AND id = :line AND revision = :revision',
            [
                'product' => $homeProductId,
                'pack' => $selectedPackId,
                'source' => 'manual',
                'explanation' => 'Product and pack selected manually.',
                'updated' => $now,
                'home' => $homeId,
                'list' => $listId,
                'line' => $lineId,
                'revision' => $expectedRevision,
            ],
        );
        if ($updated !== 1) {
            return false;
        }
        $this->connection->executeStatement(
            'UPDATE shopping_lists SET revision = revision + 1, updated_at = :updated
             WHERE home_id = :home AND id = :list',
            ['updated' => $now, 'home' => $homeId, 'list' => $listId],
        );
        $this->connection->insert('audit_events', [
            'id' => $auditEventId,
            'home_id' => $homeId,
            'actor_user_id' => $actorUserId,
            'action' => 'shopping.line.relinked',
            'target_type' => 'shopping-list-line',
            'target_id' => $lineId,
            'details' => json_encode([
                'reason' => $reason,
                'listId' => $listId,
                'before' => $before,
                'after' => $this->line($homeId, $listId, $lineId),
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
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
        if (! $this->lockOpenList($homeId, $listId)) {
            return false;
        }
        $now = $this->date($at);
        $updated = $this->connection->executeStatement(
            'UPDATE shopping_list_lines
             SET checked_at = :checked, revision = revision + 1, updated_at = :updated
             WHERE id = :id AND home_id = :home AND shopping_list_id = :list
               AND archived_at IS NULL AND revision = :revision
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

    public function updateList(
        string $homeId,
        string $listId,
        string $name,
        string $status,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE shopping_lists SET name = :name, status = :status,
                    revision = revision + 1, updated_at = :updated
             WHERE id = :list AND home_id = :home AND revision = :revision',
            [
                'name' => $name,
                'status' => $status,
                'updated' => $this->date($at),
                'list' => $listId,
                'home' => $homeId,
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function updateLine(
        string $homeId,
        string $listId,
        string $lineId,
        string $description,
        string $quantity,
        bool $archived,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool {
        if (! $this->lockOpenList($homeId, $listId)) {
            return false;
        }
        $now = $this->date($at);
        $updated = $this->connection->executeStatement(
            'UPDATE shopping_list_lines SET description = :description, quantity_to_buy = :quantity,
                    archived_at = :archived, revision = revision + 1, updated_at = :updated
             WHERE id = :line AND home_id = :home AND shopping_list_id = :list AND revision = :revision
               AND EXISTS (SELECT 1 FROM shopping_lists sl
                   WHERE sl.id = shopping_list_lines.shopping_list_id
                     AND sl.home_id = shopping_list_lines.home_id AND sl.status = :status)',
            [
                'description' => $description,
                'quantity' => $quantity,
                'archived' => $archived ? $now : null,
                'updated' => $now,
                'line' => $lineId,
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
        $purchases = DecimalSql::sum($this->connection, 'CASE WHEN r.id IS NOT NULL THEN rl.quantity ELSE 0 END');
        $positive = DecimalSql::greaterThan($this->connection, 'COALESCE(' . $purchases . ', 0)', '0');
        return $this->connection->fetchAllAssociative(
            'SELECT hp.id AS homeProductId,
                    COALESCE(hp.private_name, p.canonical_name) AS productName,
                    COALESCE(hp.original_pack_text, pk.original_pack_text, :empty) AS packText,
                    COALESCE(ib.quantity, 0) AS currentQuantity,
                    COALESCE(' . $purchases . ', 0)
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
             HAVING ' . $positive . '
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
               ON sll.shopping_list_id = sl.id AND sll.home_id = sl.home_id AND sll.archived_at IS NULL
             WHERE sl.home_id = :home AND sl.status = :status',
            ['home' => $homeId, 'status' => 'open'],
        ) ?? ['openListCount' => 0, 'lineCount' => 0, 'uncheckedLineCount' => 0];
    }

    private function validateSelection(string $homeId, ?string $homeProductId, ?string $selectedPackId): void
    {
        if ($homeProductId === null) {
            if ($selectedPackId !== null) {
                throw new \DomainException('A selected pack requires a household product.');
            }
            return;
        }
        $product = $this->one(
            'SELECT id, product_id, unit FROM home_products
             WHERE id = :id AND home_id = :home AND status = :status',
            ['id' => $homeProductId, 'home' => $homeId, 'status' => 'active'],
        );
        if ($product === null) {
            throw new \DomainException('The selected home product is unavailable.');
        }
        if ($selectedPackId === null) {
            return;
        }
        $pack = $this->one(
            'SELECT pk.normalized_base_amount AS amount, u.dimension, hp.pack_id AS homePackId,
                    base.normalized_base_amount AS baseAmount, base_unit.dimension AS baseDimension
             FROM product_packs pk LEFT JOIN units u ON u.id = pk.unit_id
             JOIN home_products hp ON hp.id = :home_product AND hp.home_id = :home
             LEFT JOIN product_packs base ON base.id = hp.pack_id
             LEFT JOIN units base_unit ON base_unit.id = base.unit_id
             WHERE pk.id = :pack AND pk.product_id = :product AND pk.status = :published',
            [
                'home_product' => $homeProductId,
                'home' => $homeId,
                'pack' => $selectedPackId,
                'product' => $product['product_id'],
                'published' => 'published',
            ],
        );
        if ($pack === null) {
            throw new \DomainException('Select a published pack belonging to this product.');
        }
        if ($product['unit'] === 'units' && $pack['homePackId'] === $selectedPackId) {
            return;
        }
        $dimension = match ($product['unit']) {
            'g', 'kg' => 'mass',
            'ml', 'l' => 'volume',
            default => $pack['homePackId'] === null ? 'count' : $pack['baseDimension'],
        };
        if (
            $dimension === null || $pack['dimension'] !== $dimension || $pack['amount'] === null
            || DecimalQuantity::quantity((string) $pack['amount'])->toString() === '0'
            || ($product['unit'] === 'units' && $pack['homePackId'] !== null
                && ($pack['baseAmount'] === null
                    || DecimalQuantity::quantity((string) $pack['baseAmount'])->toString() === '0'))
        ) {
            throw new \DomainException('The selected pack is incompatible with the stock measurement.');
        }
    }

    private function lockOpenList(string $homeId, string $listId): bool
    {
        $lock = $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';

        return $this->connection->fetchOne(
            'SELECT status FROM shopping_lists WHERE home_id = :home AND id = :list' . $lock,
            ['home' => $homeId, 'list' => $listId],
        ) === 'open';
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

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function listRecord(array $row): array
    {
        $row['revision'] = (int) $row['revision'];
        foreach (['lineCount', 'checkedCount'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function lineRecord(array $row): array
    {
        $row['revision'] = (int) $row['revision'];
        $row['quantityToBuy'] = DecimalProjection::string($row['quantityToBuy']);
        $row['quantityBasis'] = $row['source'] === 'manual' && $row['selectedPackId'] !== null
            ? 'pack-count'
            : 'stock-unit';
        $row['checked'] = $row['checkedAt'] !== null;
        return $row;
    }
}
