<?php

declare(strict_types=1);

namespace Providentia\Inventory\Infrastructure\Doctrine;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\Inventory\Domain\StockChronology;
use Providentia\SharedKernel\Infrastructure\Doctrine\DecimalProjection;

/** One read model for balance rebuilding and recommendation/backtest evidence. */
final class DbalStockChronologyReader
{
    /** @var array<string, DateTimeZone> */
    private array $timezones = [];
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function projection(string $homeId, string $homeProductId, DateTimeImmutable $asOf): array
    {
        return $this->projections($homeId, $asOf, $homeProductId)[$homeProductId]
            ?? (new StockChronology())->project([], []);
    }

    /** @return array<string, array<string, mixed>> */
    public function projections(string $homeId, DateTimeImmutable $asOf, ?string $onlyProductId = null): array
    {
        $products = $this->connection->fetchAllAssociative(
            'SELECT id, unit FROM home_products WHERE home_id = :home'
                . ($onlyProductId === null ? '' : ' AND id = :id'),
            ['home' => $homeId, ...($onlyProductId === null ? [] : ['id' => $onlyProductId])],
        );
        $counts = [];
        foreach ($this->counts($homeId, $asOf) as $row) {
            $counts[(string) $row['homeProductId']][] = $row;
        }
        $baselines = [];
        $excluded = [];
        foreach (
            $this->connection->fetchAllAssociative(
                'SELECT home_product_id, quantity, unit, recorded_at, included_movement_ids_json
             FROM stock_projection_baselines WHERE home_id = :home',
                ['home' => $homeId],
            ) as $saved
        ) {
            $productId = (string) $saved['home_product_id'];
            if (isset($counts[$productId])) {
                continue;
            }
            if ((string) $saved['recorded_at'] > $this->date($asOf)) {
                $historical = $this->historicalLegacyProjection($homeId, $productId, $asOf);
                if ($historical === null) {
                    continue;
                }
                $saved = [...$saved, ...$historical];
            }
            $saved['calendarDay'] = (new DateTimeImmutable((string) $saved['recorded_at'], new DateTimeZone('UTC')))
                ->setTimezone($this->timezone($homeId))->format('Y-m-d');
            $baselines[$productId] = $saved;
            $included = json_decode((string) $saved['included_movement_ids_json'], true, 512, JSON_THROW_ON_ERROR);
            array_push($excluded, ...$included);
        }
        $movements = [];
        foreach ($this->movements($homeId, $asOf, $excluded) as $row) {
            $movements[(string) $row['homeProductId']][] = $row;
        }
        $result = [];
        $chronology = new StockChronology();
        foreach ($products as $product) {
            $id = (string) $product['id'];
            $result[$id] = $chronology->project(
                $counts[$id] ?? [],
                $movements[$id] ?? [],
                (string) $product['unit'],
                $baselines[$id] ?? null,
            );
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function counts(string $homeId, DateTimeImmutable $asOf): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.id, s.id AS sessionId, l.home_product_id AS homeProductId, l.quantity,
                    l.stock_unit AS unit, s.observed_at AS occurredAt, s.observed_timezone AS observedTimezone
             FROM stock_count_lines l
             INNER JOIN stock_count_sessions s ON s.home_id = l.home_id AND s.id = l.session_id
             INNER JOIN home_products hp ON hp.home_id = l.home_id AND hp.id = l.home_product_id
             WHERE l.home_id = :home AND l.status = :confirmed AND s.status = :closed
               AND s.location_id IS NULL AND s.scope_complete = :complete AND s.reliability = :reliable
               AND s.observed_at IS NOT NULL AND s.observed_at <= :as_of
               AND s.closed_at <= :as_of AND s.updated_at <= :as_of AND l.updated_at <= :as_of
               AND l.stock_unit = hp.unit
             ORDER BY l.home_product_id, s.observed_at, l.id',
            ['home' => $homeId, 'confirmed' => 'confirmed', 'closed' => 'closed',
             'complete' => true, 'reliable' => 'reliable', 'as_of' => $this->date($asOf)],
        );
        // Reconstruct the accepted state at an earlier cutoff from the first later
        // audited review, rather than letting a future correction erase old evidence.
        $restored = [];
        foreach (
            $this->connection->fetchAllAssociative(
                'SELECT session_id, previous_snapshot_json FROM stock_count_reviews
             WHERE home_id = :home AND created_at > :as_of ORDER BY revision',
                ['home' => $homeId, 'as_of' => $this->date($asOf)],
            ) as $review
        ) {
            $sessionId = (string) $review['session_id'];
            if (isset($restored[$sessionId])) {
                continue;
            }
            $restored[$sessionId] = true;
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['sessionId'] !== $sessionId));
            $snapshot = json_decode((string) $review['previous_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            $session = $snapshot['session'];
            if (
                $session['status'] !== 'closed' || $session['locationId'] !== null
                || !(bool) $session['scopeComplete'] || $session['reliability'] !== 'reliable'
                || $session['observedAt'] === null || $session['observedAt'] > $this->date($asOf)
                || $session['closedAt'] > $this->date($asOf)
            ) {
                continue;
            }
            foreach ($snapshot['lines'] as $line) {
                $unit = $this->connection->fetchOne('SELECT unit FROM home_products
                    WHERE home_id = :home AND id = :product', ['home' => $homeId, 'product' => $line['homeProductId']]);
                if ($line['status'] === 'confirmed' && $line['unit'] !== null && $line['unit'] === $unit) {
                    $rows[] = ['id' => $line['id'], 'sessionId' => $sessionId,
                        'homeProductId' => $line['homeProductId'], 'quantity' => $line['quantity'],
                        'unit' => $line['unit'], 'occurredAt' => $session['observedAt'],
                        'observedTimezone' => $session['observedTimezone'] ?? null];
                }
            }
        }
        foreach ($rows as &$row) {
            $row['calendarDay'] = (new DateTimeImmutable((string) $row['occurredAt'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone((string) ($row['observedTimezone'] ?? 'UTC')))->format('Y-m-d');
        }
        unset($row);
        return $rows;
    }

    /**
     * Returns one net movement per logical receipt line/product. Quantity corrections
     * change that purchase rather than becoming artificial present-day outflows.
     * @param list<string> $excludedIds
     * @return list<array<string, mixed>>
     */
    public function movements(string $homeId, DateTimeImmutable $asOf, array $excludedIds = []): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, home_product_id AS homeProductId, movement_type AS movementType,
                    quantity_delta AS quantityDelta, source_type AS sourceType, source_id AS sourceId,
                    occurred_at AS occurredAt, stock_unit AS unit, receipt_line_id AS receiptLineId
             FROM stock_movements WHERE home_id = :home AND created_at <= :as_of
               AND movement_type <> :count_type
             ORDER BY created_at, id',
            ['home' => $homeId, 'as_of' => $this->date($asOf), 'count_type' => 'count-reconciliation'],
        );
        $corrections = [];
        foreach (
            $this->connection->fetchAllAssociative(
                'SELECT receipt_line_id, purchase_date FROM stock_purchase_date_corrections
             WHERE home_id = :home AND created_at <= :as_of ORDER BY revision',
                ['home' => $homeId, 'as_of' => $this->date($asOf)],
            ) as $correction
        ) {
            $corrections[(string) $correction['receipt_line_id']] = (string) $correction['purchase_date'];
        }
        $purchases = [];
        $purchaseUnits = [];
        $movements = [];
        foreach ($rows as $row) {
            if (in_array((string) $row['id'], $excludedIds, true)) {
                continue;
            }
            $row['quantityDelta'] = DecimalProjection::string($row['quantityDelta']);
            $row['dateOnly'] = in_array($row['sourceType'], ['receipt-line', 'receipt-line-correction'], true);
            if (! $row['dateOnly']) {
                $row['quantity'] = $row['quantityDelta'];
                $movements[] = $row;
                continue;
            }
            $lineId = (string) ($row['receiptLineId'] ?? explode(':', (string) $row['sourceId'], 2)[0]);
            $key = $lineId . ':' . (string) $row['homeProductId'];
            $purchaseUnits[$key][(string) $row['unit']] = true;
            if (! isset($purchases[$key])) {
                $purchases[$key] = $row;
                $purchases[$key]['quantityDelta'] = '0';
                $purchases[$key]['receiptLineId'] = $lineId;
            }
            $purchases[$key]['quantityDelta'] = BigDecimal::of((string) $purchases[$key]['quantityDelta'])
                ->plus(BigDecimal::of($row['quantityDelta']))->strippedOfTrailingZeros()->__toString();
            if ($row['sourceType'] === 'receipt-line') {
                $purchases[$key]['id'] = $row['id'];
                $purchases[$key]['sourceType'] = 'receipt-line';
                $purchases[$key]['occurredAt'] = $row['occurredAt'];
            }
            if (isset($corrections[$lineId])) {
                $purchases[$key]['occurredAt'] = $corrections[$lineId] . ' 00:00:00';
            }
        }
        foreach ($purchases as $key => $row) {
            if (count($purchaseUnits[$key]) > 1) {
                $row['unit'] = null;
            }
            $row['quantity'] = $row['quantityDelta'];
            $movements[] = $row;
        }
        $end = $this->date($asOf);
        $localDay = $asOf->setTimezone($this->timezone($homeId))->format('Y-m-d');
        $movements = array_values(array_filter(
            $movements,
            static fn (array $row): bool => $row['dateOnly']
                ? substr((string) $row['occurredAt'], 0, 10) <= $localDay
                : (string) $row['occurredAt'] <= $end,
        ));
        usort($movements, static fn (array $left, array $right): int =>
            [(string) $left['homeProductId'], (string) $left['occurredAt'], (string) $left['id']]
            <=> [(string) $right['homeProductId'], (string) $right['occurredAt'], (string) $right['id']]);
        return $movements;
    }

    /**
     * Preserve the old ledger's best-known projection at pre-migration cutoffs.
     * Its derived count deltas are never promoted to trusted observations.
     * @return array<string, mixed>|null
     */
    private function historicalLegacyProjection(string $homeId, string $productId, DateTimeImmutable $asOf): ?array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, quantity_delta, movement_type, source_type, occurred_at FROM stock_movements
             WHERE home_id = :home AND home_product_id = :product AND created_at <= :at',
            ['home' => $homeId, 'product' => $productId, 'at' => $this->date($asOf)],
        );
        $quantity = BigDecimal::zero();
        $included = [];
        $hasLegacyCount = false;
        $day = $asOf->setTimezone($this->timezone($homeId))->format('Y-m-d');
        foreach ($rows as $row) {
            $dateOnly = in_array($row['source_type'], ['receipt-line', 'receipt-line-correction'], true);
            if (
                ($dateOnly && substr((string) $row['occurred_at'], 0, 10) > $day)
                || (!$dateOnly && (string) $row['occurred_at'] > $this->date($asOf))
            ) {
                continue;
            }
            $quantity = $quantity->plus(DecimalProjection::string($row['quantity_delta']));
            $included[] = (string) $row['id'];
            $hasLegacyCount = $hasLegacyCount || $row['movement_type'] === 'count-reconciliation';
        }
        if (!$hasLegacyCount) {
            return null;
        }
        return [
            'quantity' => (string) $quantity->strippedOfTrailingZeros(), 'recorded_at' => $this->date($asOf),
            'included_movement_ids_json' => json_encode($included, JSON_THROW_ON_ERROR), 'historical' => true,
        ];
    }

    private function timezone(string $homeId): DateTimeZone
    {
        if (!isset($this->timezones[$homeId])) {
            $zone = $this->connection->fetchOne(
                'SELECT default_timezone FROM homes WHERE id = :home',
                ['home' => $homeId],
            );
            $this->timezones[$homeId] = new DateTimeZone($zone === false ? 'UTC' : (string) $zone);
        }
        return $this->timezones[$homeId];
    }

    private function date(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
