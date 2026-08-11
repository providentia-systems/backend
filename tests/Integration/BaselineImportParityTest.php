<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Administration\Infrastructure\Doctrine\DbalBaselineImportStore;

final class BaselineImportParityTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';

    /** @var array<int, string> */
    private const REVIEWED_LINKS = [
        26 => 'review-ground-coffee-jacobs-barista-classic-pack-size-pending-279',
        30 => 'review-tomato-sauce-all-gold-pack-size-pending-282',
        31 => 'review-tomato-sauce-pack-size-pending-283',
        32 => 'review-sweet-chilli-sauce-pack-size-pending-284',
        46 => 'review-oxi-laundry-stain-remover-pack-size-pending-287',
        50 => 'review-thin-bleach-pack-size-pending-288',
        55 => 'review-insecticide-repellent-tabard-pack-size-pending-289',
        56 => 'review-air-freshener-pack-size-pending-290',
        59 => 'review-steel-wool-scrubbies-pack-size-pending-292',
    ];

    private Connection $connection;
    private DbalBaselineImportStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ($this->schema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->connection->insert('home_memberships', [
            'home_id' => self::HOME_ID,
            'user_id' => self::USER_ID,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->store = new DbalBaselineImportStore($this->connection, new SequenceUuidGenerator());
    }

    public function testAuthoritativeOpeningIdentityLinksPreserveParityAndReplayExactly(): void
    {
        $data = $this->fixture();
        foreach ($data['itemMaster'] as $index => $item) {
            $this->connection->insert('product_packs', [
                'id' => 'pack-' . $index,
                'product_id' => 'product-' . $index,
                'source_key' => $item['id'],
                'status' => 'published',
            ]);
        }
        $reconciliation = [
            'itemMasterRows' => 292,
            'openingStockLines' => 60,
            'openingStockQuantity' => 159,
            'recentPurchaseLines' => 16,
            'historicalPurchaseLines' => 452,
        ];

        $first = $this->store->import(
            self::HOME_ID,
            self::USER_ID,
            'fixture-data',
            'fixture-rules',
            $data,
            [],
            $reconciliation,
            new DateTimeImmutable('2026-08-11T08:00:00+00:00'),
        );
        $beforeReplay = $this->tableCounts();
        $replay = $this->store->import(
            self::HOME_ID,
            self::USER_ID,
            'fixture-data',
            'fixture-rules',
            $data,
            [],
            $reconciliation,
            new DateTimeImmutable('2026-08-11T09:00:00+00:00'),
        );

        self::assertSame(32, $first['catalogLinked']);
        self::assertSame(28, $first['privateProducts']);
        self::assertSame(60, $first['countLines']);
        self::assertSame(159, $first['quantity']);
        self::assertSame(16, $first['priceObservations']);
        self::assertSame(468, $first['lines']);
        self::assertSame(456, $first['approvedMatches']);
        self::assertSame(12, $first['unresolvedLines']);
        self::assertFalse($first['replayed']);
        self::assertTrue($replay['replayed']);
        self::assertSame($beforeReplay, $this->tableCounts());

        self::assertSame(292, $this->countRows('product_packs'));
        self::assertSame(292, (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT source_key) FROM product_packs',
        ));
        self::assertSame(60, $this->countRows('home_products'));
        self::assertSame(32, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM home_products WHERE pack_id IS NOT NULL AND private_name IS NULL',
        ));
        self::assertSame(28, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM home_products WHERE pack_id IS NULL AND private_name IS NOT NULL',
        ));
        self::assertSame(60, $this->countRows('stock_count_lines'));
        self::assertSame(60, $this->countRows('inventory_balances'));
        self::assertSame(159, (int) $this->connection->fetchOne(
            'SELECT SUM(quantity) FROM inventory_balances',
        ));
        self::assertSame(16, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM receipt_lines rl
             INNER JOIN receipts r ON r.id = rl.receipt_id
             WHERE r.source = :source',
            ['source' => 'baseline-recent'],
        ));
        self::assertSame(9, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM stock_movements sm
             INNER JOIN home_products hp ON hp.id = sm.home_product_id
             WHERE sm.source_id IN (:one, :two, :three, :four, :five, :six, :seven, :eight, :nine)
               AND hp.pack_id IS NOT NULL AND hp.private_name IS NULL',
            [
                'one' => 'stock-26',
                'two' => 'stock-30',
                'three' => 'stock-31',
                'four' => 'stock-32',
                'five' => 'stock-46',
                'six' => 'stock-50',
                'seven' => 'stock-55',
                'eight' => 'stock-56',
                'nine' => 'stock-59',
            ],
        ));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function fixture(): array
    {
        $reviewedStockNumbers = array_keys(self::REVIEWED_LINKS);
        $items = [];
        for ($index = 0; $index < 292; $index++) {
            $reviewedIndex = $index - 23;
            $stockNumber = $reviewedStockNumbers[$reviewedIndex] ?? null;
            $items[] = [
                'id' => $stockNumber === null ? 'item-' . $index : self::REVIEWED_LINKS[$stockNumber],
                'product' => $stockNumber === null
                    ? 'Catalog product ' . $index
                    : 'Reviewed product ' . $stockNumber,
                'brand' => $stockNumber === null ? '' : 'Reviewed brand ' . $stockNumber,
                'packSize' => $stockNumber === null ? '1 unit' : 'Pack size pending',
            ];
        }
        $stock = [];
        for ($index = 0; $index < 60; $index++) {
            $stockNumber = $index + 1;
            $reviewed = isset(self::REVIEWED_LINKS[$stockNumber]);
            $stock[] = [
                'id' => 'stock-' . $stockNumber,
                'product' => $stockNumber <= 23
                    ? 'Catalog product ' . $index
                    : ($reviewed ? 'Reviewed product ' . $stockNumber : 'Private product ' . $stockNumber),
                'brand' => $reviewed ? 'Reviewed brand ' . $stockNumber : '',
                'packSize' => $reviewed ? '' : '1 unit',
                'quantity' => $index === 59 ? 41 : 2,
                'confidence' => 'High',
                'notes' => '',
            ];
        }
        $history = [];
        for ($index = 0; $index < 452; $index++) {
            $history[] = [
                'id' => 'history-' . $index,
                'date' => '2026-04-01',
                'fullName' => 'Catalog product 0 - 1 unit',
                'quantity' => 1,
                'size' => '1 unit',
                'canonicalItem' => 'Catalog product 0',
                'canonicalPackSize' => '1 unit',
            ];
        }
        $recent = [];
        for ($index = 0; $index < 16; $index++) {
            $group = $index % 8;
            $matched = $index < 4;
            $recent[] = [
                'id' => 'recent-' . $index,
                'date' => sprintf('2026-07-%02d', $group + 1),
                'store' => 'Fixture store ' . $group,
                'product' => 'Raw recent product ' . $index,
                'packSize' => '1 unit',
                'quantity' => 1,
                'totalCost' => '10.00',
                'canonicalItem' => $matched ? 'Catalog product 0' : '',
                'canonicalPackSize' => $matched ? '1 unit' : '',
            ];
        }

        return [
            'itemMaster' => $items,
            'currentStock' => $stock,
            'history' => $history,
            'purchases' => $recent,
        ];
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        return [
            'runs' => $this->countRows('baseline_import_runs'),
            'mappings' => $this->countRows('baseline_import_mappings'),
            'homeProducts' => $this->countRows('home_products'),
            'countLines' => $this->countRows('stock_count_lines'),
            'movements' => $this->countRows('stock_movements'),
            'balances' => $this->countRows('inventory_balances'),
            'receipts' => $this->countRows('receipts'),
            'receiptLines' => $this->countRows('receipt_lines'),
            'matches' => $this->countRows('receipt_line_matches'),
            'prices' => $this->countRows('price_observations'),
        ];
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE home_memberships (
                home_id TEXT NOT NULL, user_id TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL
            )',
            'CREATE TABLE baseline_import_runs (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, source_commit TEXT NOT NULL,
                archive_sha256 TEXT NOT NULL, mode TEXT NOT NULL, status TEXT NOT NULL,
                reconciliation_json TEXT NOT NULL, started_at TEXT NOT NULL, completed_at TEXT NULL
            )',
            'CREATE TABLE baseline_import_mappings (
                import_run_id TEXT NOT NULL, source_type TEXT NOT NULL, source_id TEXT NOT NULL,
                destination_type TEXT NOT NULL, destination_id TEXT NOT NULL,
                source_digest TEXT NOT NULL, created_at TEXT NOT NULL,
                PRIMARY KEY (import_run_id, source_type, source_id)
            )',
            'CREATE TABLE baseline_import_quarantine (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, import_run_id TEXT NOT NULL,
                source_type TEXT NOT NULL, source_id TEXT NOT NULL, raw_payload_json TEXT NOT NULL,
                reason TEXT NOT NULL, resolution_status TEXT NOT NULL, created_at TEXT NOT NULL
            )',
            'CREATE TABLE product_packs (
                id TEXT PRIMARY KEY, product_id TEXT NOT NULL, source_key TEXT NOT NULL,
                status TEXT NOT NULL
            )',
            'CREATE TABLE home_locations (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, name TEXT NOT NULL,
                normalized_name TEXT NOT NULL, kind TEXT NOT NULL, status TEXT NOT NULL,
                revision INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE stock_count_sessions (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, location_id TEXT NOT NULL,
                status TEXT NOT NULL, notes TEXT NOT NULL, revision INTEGER NOT NULL,
                opened_by_user_id TEXT NOT NULL, opened_at TEXT NOT NULL,
                closed_by_user_id TEXT NOT NULL, closed_at TEXT NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE home_products (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, product_id TEXT NULL, pack_id TEXT NULL,
                private_name TEXT NULL, normalized_private_name TEXT NULL,
                original_pack_text TEXT NOT NULL, status TEXT NOT NULL, revision INTEGER NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE stock_count_lines (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, session_id TEXT NOT NULL,
                home_product_id TEXT NOT NULL, quantity TEXT NOT NULL, confidence TEXT NULL,
                source TEXT NOT NULL, notes TEXT NOT NULL, status TEXT NOT NULL,
                revision INTEGER NOT NULL, counted_by_user_id TEXT NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE stock_movements (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, home_product_id TEXT NOT NULL,
                movement_type TEXT NOT NULL, quantity_delta TEXT NOT NULL,
                source_type TEXT NOT NULL, source_id TEXT NOT NULL, reason TEXT NOT NULL,
                actor_user_id TEXT NOT NULL, reversed_movement_id TEXT NULL,
                occurred_at TEXT NOT NULL, created_at TEXT NOT NULL
            )',
            'CREATE TABLE inventory_balances (
                home_id TEXT NOT NULL, home_product_id TEXT NOT NULL, quantity TEXT NOT NULL,
                last_movement_id TEXT NOT NULL, revision INTEGER NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE stores (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, name TEXT NOT NULL,
                normalized_name TEXT NOT NULL, location TEXT NOT NULL, status TEXT NOT NULL,
                revision INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE receipts (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, store_id TEXT NULL,
                purchase_date TEXT NOT NULL, currency TEXT NOT NULL, total_amount TEXT NULL,
                status TEXT NOT NULL, source TEXT NOT NULL, source_reference TEXT NOT NULL,
                notes TEXT NOT NULL, revision INTEGER NOT NULL, created_by_user_id TEXT NOT NULL,
                committed_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE receipt_lines (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, receipt_id TEXT NOT NULL,
                line_number INTEGER NOT NULL, raw_description TEXT NOT NULL, quantity TEXT NOT NULL,
                original_pack_text TEXT NOT NULL, unit_price TEXT NULL, line_total TEXT NULL,
                home_product_id TEXT NULL, approval_status TEXT NOT NULL, revision INTEGER NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
            'CREATE TABLE receipt_line_matches (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, receipt_line_id TEXT NOT NULL,
                product_pack_id TEXT NOT NULL, match_method TEXT NOT NULL, confidence TEXT NOT NULL,
                status TEXT NOT NULL, decided_by_user_id TEXT NOT NULL, decided_at TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE price_observations (
                id TEXT PRIMARY KEY, home_id TEXT NOT NULL, receipt_line_id TEXT NOT NULL,
                product_pack_id TEXT NULL, store_id TEXT NULL, currency TEXT NOT NULL,
                quantity TEXT NOT NULL, unit_price TEXT NULL, line_total TEXT NOT NULL,
                observed_at TEXT NOT NULL, created_at TEXT NOT NULL
            )',
        ];
    }
}
