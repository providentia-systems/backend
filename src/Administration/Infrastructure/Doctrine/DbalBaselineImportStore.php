<?php

declare(strict_types=1);

namespace Providentia\Administration\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Providentia\Administration\Application\BaselineImportStore;
use Providentia\SharedKernel\Application\UuidGenerator;

final class DbalBaselineImportStore implements BaselineImportStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UuidGenerator $ids,
    ) {
    }

    public function import(
        string $homeId,
        string $actorUserId,
        string $dataDigest,
        string $rulesDigest,
        array $data,
        array $rules,
        array $reconciliation,
        DateTimeImmutable $at,
    ): array {
        $this->assertImportAuthority($homeId, $actorUserId);
        $sourceDigest = hash('sha256', $dataDigest . ':' . $rulesDigest);
        $existing = $this->one(
            'SELECT reconciliation_json FROM baseline_import_runs
             WHERE home_id = :home AND source_commit = :source_commit
               AND archive_sha256 = :digest AND mode = :mode AND status = :status',
            [
                'home' => $homeId,
                'source_commit' => 'b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8',
                'digest' => $sourceDigest,
                'mode' => 'commit',
                'status' => 'completed',
            ],
        );
        if ($existing !== null) {
            /** @var array<string, int|string|bool> $report */
            $report = json_decode((string) $existing['reconciliation_json'], true, 512, JSON_THROW_ON_ERROR);
            $report['replayed'] = true;

            return $report;
        }
        $runId = $this->ids->generate();
        $now = $this->date($at);
        $this->connection->insert('baseline_import_runs', [
            'id' => $runId,
            'home_id' => $homeId,
            'source_commit' => 'b01b5ef14783b4ad1c1bfc0be7ba0dba32629af8',
            'archive_sha256' => $sourceDigest,
            'mode' => 'commit',
            'status' => 'running',
            'reconciliation_json' => '{}',
            'started_at' => $now,
            'completed_at' => null,
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = $data['itemMaster'];
        [$exactItems, $looseItems] = $this->catalogIndexes($items);
        $locationId = $this->createUnspecifiedLocation($homeId, $now);
        /** @var list<array<string, mixed>> $openingStock */
        $openingStock = $data['currentStock'];
        $opening = $this->importOpeningStock(
            $runId,
            $homeId,
            $actorUserId,
            $locationId,
            $openingStock,
            $exactItems,
            $now,
        );
        /** @var list<array<string, mixed>> $history */
        $history = $data['history'];
        /** @var list<array<string, mixed>> $recent */
        $recent = $data['purchases'];
        $purchases = $this->importPurchases(
            $runId,
            $homeId,
            $actorUserId,
            $history,
            $recent,
            $looseItems,
            $now,
        );
        if (
            $opening !== ['catalogLinked' => 23, 'privateProducts' => 37, 'countLines' => 60, 'quantity' => 159]
            || $purchases !== [
                'receipts' => 9,
                'lines' => 468,
                'approvedMatches' => 456,
                'unresolvedLines' => 12,
                'priceObservations' => 16,
            ]
        ) {
            throw new \RuntimeException('Post-import Phase 5 reconciliation failed.');
        }
        $report = array_merge($reconciliation, $opening, $purchases, ['replayed' => false]);
        $this->connection->update('baseline_import_runs', [
            'status' => 'completed',
            'reconciliation_json' => json_encode($report, JSON_THROW_ON_ERROR),
            'completed_at' => $now,
        ], ['id' => $runId, 'home_id' => $homeId]);

        return $report;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{0: array<string, array<string, string>|null>, 1: array<string, array<string, string>|null>}
     */
    private function catalogIndexes(array $items): array
    {
        $exact = [];
        $loose = [];
        foreach ($items as $item) {
            $sourceId = (string) ($item['id'] ?? '');
            $pack = $this->one(
                'SELECT pk.id AS packId, pk.product_id AS productId
                 FROM product_packs pk WHERE pk.source_key = :source',
                ['source' => $sourceId],
            );
            if ($pack === null) {
                throw new \RuntimeException('Catalog seed must be imported before the Phase 5 baseline.');
            }
            $value = ['packId' => (string) $pack['packId'], 'productId' => (string) $pack['productId']];
            $exactKey = $this->identityKey(
                (string) ($item['product'] ?? ''),
                (string) ($item['brand'] ?? ''),
                (string) ($item['packSize'] ?? ''),
            );
            $looseKey = $this->looseKey(
                (string) ($item['product'] ?? ''),
                (string) ($item['packSize'] ?? ''),
            );
            $exact[$exactKey] = array_key_exists($exactKey, $exact) ? null : $value;
            $loose[$looseKey] = array_key_exists($looseKey, $loose) ? null : $value;
        }

        return [$exact, $loose];
    }

    private function createUnspecifiedLocation(string $homeId, string $now): string
    {
        $existing = $this->connection->fetchOne(
            'SELECT id FROM home_locations
             WHERE home_id = :home AND normalized_name = :name',
            ['home' => $homeId, 'name' => 'unspecified'],
        );
        if (is_string($existing)) {
            return $existing;
        }
        $id = $this->ids->generate();
        $this->connection->insert('home_locations', [
            'id' => $id,
            'home_id' => $homeId,
            'name' => 'Unspecified',
            'normalized_name' => 'unspecified',
            'kind' => 'other',
            'status' => 'active',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, array<string, string>|null> $exactItems
     * @return array{catalogLinked: int, privateProducts: int, countLines: int, quantity: int}
     */
    private function importOpeningStock(
        string $runId,
        string $homeId,
        string $actorUserId,
        string $locationId,
        array $rows,
        array $exactItems,
        string $now,
    ): array {
        $sessionId = $this->ids->generate();
        $this->connection->insert('stock_count_sessions', [
            'id' => $sessionId,
            'home_id' => $homeId,
            'location_id' => $locationId,
            'status' => 'closed',
            'notes' => 'Verified Providentia v1 opening physical count.',
            'revision' => 2,
            'opened_by_user_id' => $actorUserId,
            'opened_at' => $now,
            'closed_by_user_id' => $actorUserId,
            'closed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $catalogLinked = 0;
        $privateProducts = 0;
        $quantityTotal = 0;
        foreach ($rows as $row) {
            $sourceId = (string) ($row['id'] ?? '');
            $match = $exactItems[$this->identityKey(
                (string) ($row['product'] ?? ''),
                (string) ($row['brand'] ?? ''),
                (string) ($row['packSize'] ?? ''),
            )] ?? null;
            $homeProductId = $this->ids->generate();
            $linked = is_array($match);
            $this->connection->insert('home_products', [
                'id' => $homeProductId,
                'home_id' => $homeId,
                'product_id' => $linked ? $match['productId'] : null,
                'pack_id' => $linked ? $match['packId'] : null,
                'private_name' => $linked ? null : (string) ($row['product'] ?? ''),
                'normalized_private_name' => $linked
                    ? null
                    : $this->normalize((string) ($row['product'] ?? '')),
                'original_pack_text' => (string) ($row['packSize'] ?? ''),
                'status' => 'active',
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $linked ? $catalogLinked++ : $privateProducts++;
            $lineId = $this->ids->generate();
            $quantity = (string) ($row['quantity'] ?? '0');
            $quantityTotal += (int) $quantity;
            $confidence = match (mb_strtolower((string) ($row['confidence'] ?? ''))) {
                'high' => '0.9500',
                'medium' => '0.7000',
                'low' => '0.4000',
                default => null,
            };
            $this->connection->insert('stock_count_lines', [
                'id' => $lineId,
                'home_id' => $homeId,
                'session_id' => $sessionId,
                'home_product_id' => $homeProductId,
                'quantity' => $quantity,
                'confidence' => $confidence,
                'source' => 'import',
                'notes' => (string) ($row['notes'] ?? ''),
                'status' => 'confirmed',
                'revision' => 1,
                'counted_by_user_id' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $movementId = $this->ids->generate();
            $this->connection->insert('stock_movements', [
                'id' => $movementId,
                'home_id' => $homeId,
                'home_product_id' => $homeProductId,
                'movement_type' => 'opening-count',
                'quantity_delta' => $quantity,
                'source_type' => 'baseline-opening-stock',
                'source_id' => $sourceId,
                'reason' => 'Verified opening physical count',
                'actor_user_id' => $actorUserId,
                'reversed_movement_id' => null,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
            $this->connection->insert('inventory_balances', [
                'home_id' => $homeId,
                'home_product_id' => $homeProductId,
                'quantity' => $quantity,
                'last_movement_id' => $movementId,
                'revision' => 1,
                'updated_at' => $now,
            ]);
            $this->mapping($runId, 'opening-stock', $sourceId, 'stock-count-line', $lineId, $row, $now);
        }

        return [
            'catalogLinked' => $catalogLinked,
            'privateProducts' => $privateProducts,
            'countLines' => count($rows),
            'quantity' => $quantityTotal,
        ];
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param list<array<string, mixed>> $recent
     * @param array<string, array<string, string>|null> $looseItems
     * @return array{receipts: int, lines: int, approvedMatches: int, unresolvedLines: int, priceObservations: int}
     */
    private function importPurchases(
        string $runId,
        string $homeId,
        string $actorUserId,
        array $history,
        array $recent,
        array $looseItems,
        string $now,
    ): array {
        $groups = [];
        foreach ($history as $row) {
            $key = 'history|' . (string) ($row['date'] ?? '');
            $groups[$key] ??= [
                'source' => 'baseline-history',
                'date' => (string) ($row['date'] ?? ''),
                'store' => null,
                'rows' => [],
            ];
            $groups[$key]['rows'][] = $row;
        }
        foreach ($recent as $row) {
            $store = trim((string) ($row['store'] ?? ''));
            $key = 'recent|' . (string) ($row['date'] ?? '') . '|' . $this->normalize($store);
            $groups[$key] ??= [
                'source' => 'baseline-recent',
                'date' => (string) ($row['date'] ?? ''),
                'store' => $store,
                'rows' => [],
            ];
            $groups[$key]['rows'][] = $row;
        }
        $approved = 0;
        $unresolved = 0;
        $prices = 0;
        foreach ($groups as $sourceReference => $group) {
            $storeId = $group['store'] === null
                ? null
                : $this->store($homeId, (string) $group['store'], $now);
            $receiptId = $this->ids->generate();
            $isRecent = $group['source'] === 'baseline-recent';
            $total = null;
            if ($isRecent) {
                $sum = 0.0;
                foreach ($group['rows'] as $row) {
                    $sum += (float) ($row['totalCost'] ?? 0);
                }
                $total = number_format($sum, 2, '.', '');
            }
            $this->connection->insert('receipts', [
                'id' => $receiptId,
                'home_id' => $homeId,
                'store_id' => $storeId,
                'purchase_date' => $group['date'],
                'currency' => 'NAD',
                'total_amount' => $total,
                'status' => 'committed',
                'source' => $group['source'],
                'source_reference' => $sourceReference,
                'notes' => $isRecent
                    ? 'Imported recent purchase evidence; no receipt media was supplied.'
                    : 'Imported legacy shopping history; this is not evidence of a scanned receipt.',
                'revision' => 1,
                'created_by_user_id' => $actorUserId,
                'committed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach (array_values($group['rows']) as $index => $row) {
                $lineId = $this->ids->generate();
                $canonical = trim((string) ($row['canonicalItem'] ?? ''));
                $canonicalPack = trim((string) ($row['canonicalPackSize'] ?? ''));
                $match = $canonical === '' || $canonicalPack === ''
                    ? null
                    : ($looseItems[$this->looseKey($canonical, $canonicalPack)] ?? null);
                if (! $isRecent && ! is_array($match)) {
                    throw new \RuntimeException('An authoritative historical purchase could not be mapped uniquely.');
                }
                $matched = is_array($match);
                $matched ? $approved++ : $unresolved++;
                $rawDescription = $isRecent
                    ? (string) ($row['product'] ?? '')
                    : (string) ($row['fullName'] ?? '');
                $packText = $isRecent
                    ? (string) ($row['packSize'] ?? '')
                    : (string) ($row['size'] ?? '');
                $lineTotal = $isRecent
                    ? number_format((float) ($row['totalCost'] ?? 0), 2, '.', '')
                    : null;
                $this->connection->insert('receipt_lines', [
                    'id' => $lineId,
                    'home_id' => $homeId,
                    'receipt_id' => $receiptId,
                    'line_number' => $index + 1,
                    'raw_description' => $rawDescription,
                    'quantity' => (string) ($row['quantity'] ?? '0'),
                    'original_pack_text' => $packText,
                    'unit_price' => null,
                    'line_total' => $lineTotal,
                    'home_product_id' => null,
                    'approval_status' => $matched ? 'approved-catalog' : 'unresolved',
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($matched) {
                    $this->connection->insert('receipt_line_matches', [
                        'id' => $this->ids->generate(),
                        'home_id' => $homeId,
                        'receipt_line_id' => $lineId,
                        'product_pack_id' => $match['packId'],
                        'match_method' => 'authoritative-canonical-export',
                        'confidence' => '1',
                        'status' => 'approved',
                        'decided_by_user_id' => $actorUserId,
                        'decided_at' => $now,
                        'created_at' => $now,
                    ]);
                } else {
                    $this->quarantine(
                        $runId,
                        $homeId,
                        'recent-purchase',
                        (string) ($row['id'] ?? ''),
                        $row,
                        'No approved canonical product-and-pack link in the authoritative export.',
                        $now,
                    );
                }
                if ($isRecent && $lineTotal !== null) {
                    $this->connection->insert('price_observations', [
                        'id' => $this->ids->generate(),
                        'home_id' => $homeId,
                        'receipt_line_id' => $lineId,
                        'product_pack_id' => $matched ? $match['packId'] : null,
                        'store_id' => $storeId,
                        'currency' => 'NAD',
                        'quantity' => (string) ($row['quantity'] ?? '0'),
                        'unit_price' => null,
                        'line_total' => $lineTotal,
                        'observed_at' => $group['date'] . ' 00:00:00',
                        'created_at' => $now,
                    ]);
                    $prices++;
                }
                $sourceType = $isRecent ? 'recent-purchase' : 'historical-purchase';
                $this->mapping(
                    $runId,
                    $sourceType,
                    (string) ($row['id'] ?? ''),
                    'receipt-line',
                    $lineId,
                    $row,
                    $now,
                );
            }
        }

        return [
            'receipts' => count($groups),
            'lines' => count($history) + count($recent),
            'approvedMatches' => $approved,
            'unresolvedLines' => $unresolved,
            'priceObservations' => $prices,
        ];
    }

    private function store(string $homeId, string $name, string $now): string
    {
        $normalized = $this->normalize($name);
        $existing = $this->connection->fetchOne(
            'SELECT id FROM stores
             WHERE home_id = :home AND normalized_name = :name AND location = :location',
            ['home' => $homeId, 'name' => $normalized, 'location' => ''],
        );
        if (is_string($existing)) {
            return $existing;
        }
        $id = $this->ids->generate();
        $this->connection->insert('stores', [
            'id' => $id,
            'home_id' => $homeId,
            'name' => $name,
            'normalized_name' => $normalized,
            'location' => '',
            'status' => 'active',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array<string, mixed> $payload */
    private function mapping(
        string $runId,
        string $sourceType,
        string $sourceId,
        string $destinationType,
        string $destinationId,
        array $payload,
        string $now,
    ): void {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->connection->insert('baseline_import_mappings', [
            'import_run_id' => $runId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'destination_type' => $destinationType,
            'destination_id' => $destinationId,
            'source_digest' => hash('sha256', $encoded),
            'created_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function quarantine(
        string $runId,
        string $homeId,
        string $sourceType,
        string $sourceId,
        array $payload,
        string $reason,
        string $now,
    ): void {
        $this->connection->insert('baseline_import_quarantine', [
            'id' => $this->ids->generate(),
            'home_id' => $homeId,
            'import_run_id' => $runId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'raw_payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'reason' => $reason,
            'resolution_status' => 'unresolved',
            'created_at' => $now,
        ]);
    }

    private function assertImportAuthority(string $homeId, string $actorUserId): void
    {
        $role = $this->connection->fetchOne(
            'SELECT role FROM home_memberships
             WHERE home_id = :home AND user_id = :user AND status = :status',
            ['home' => $homeId, 'user' => $actorUserId, 'status' => 'active'],
        );
        if (! in_array($role, ['owner', 'manager'], true)) {
            throw new \DomainException('Baseline import requires an active owner or manager.');
        }
    }

    private function identityKey(string $product, string $brand, string $pack): string
    {
        return implode('|', [$this->normalize($product), $this->normalize($brand), $this->normalizePack($pack)]);
    }

    private function looseKey(string $product, string $pack): string
    {
        return $this->normalize($product) . '|' . $this->normalizePack($pack);
    }

    private function normalizePack(string $value): string
    {
        return str_replace(' ', '', $this->normalize($value));
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
