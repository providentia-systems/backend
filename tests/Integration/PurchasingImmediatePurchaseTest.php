<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Laminas\Diactoros\ServerRequest;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Purchasing\Http\PurchasingHandler;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryMovementGateway;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Purchasing\Infrastructure\Doctrine\DbalPurchasingStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Infrastructure\Doctrine\DbalShoppingIntelligenceStore;

final class PurchasingImmediatePurchaseTest extends TestCase
{
    private const HOME = '01912345-6789-7abc-8def-0123456789ab';
    private const RECEIPT = '01912345-6789-7abc-9def-0123456789ab';
    private const LINE = '01912345-6789-7abc-adef-0123456789ab';
    private const ITEM = '01912345-6789-7abc-bdef-0123456789ab';
    private const PACK = '01912345-6789-7abc-8def-1123456789ab';
    private const USER = '01912345-6789-7abc-ddef-0123456789ab';
    private const STORE = '01912345-6789-7abc-8def-2123456789ab';

    private Connection $connection;
    private PurchasingService $service;
    private DbalPurchasingStore $store;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (
            [
            'CREATE TABLE receipts (id TEXT PRIMARY KEY, home_id TEXT, store_id TEXT,
             purchase_date TEXT, currency TEXT, total_amount TEXT, status TEXT, source TEXT,
             source_reference TEXT, notes TEXT, revision INTEGER, created_by_user_id TEXT,
             committed_at TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE receipt_lines (id TEXT PRIMARY KEY, home_id TEXT, receipt_id TEXT,
             line_number INTEGER, raw_description TEXT, quantity TEXT, original_pack_text TEXT,
             unit_price TEXT, line_total TEXT, home_product_id TEXT, approval_status TEXT,
             selected_pack_id TEXT, stock_quantity TEXT, stock_quantity_per_pack TEXT, stock_unit TEXT,
             revision INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE home_products (id TEXT, home_id TEXT, product_id TEXT, pack_id TEXT,
             private_name TEXT, unit TEXT, status TEXT)',
            'CREATE TABLE products (id TEXT, canonical_name TEXT)',
            'CREATE TABLE product_packs (id TEXT, product_id TEXT, unit_id TEXT,
             normalized_base_amount TEXT, original_pack_text TEXT, status TEXT)',
            'CREATE TABLE units (id TEXT, dimension TEXT)',
            'CREATE TABLE receipt_line_matches (id TEXT, home_id TEXT, receipt_line_id TEXT,
             product_pack_id TEXT, match_method TEXT, confidence TEXT, status TEXT, decided_by_user_id TEXT,
             decided_at TEXT, created_at TEXT)',
            'CREATE TABLE stores (id TEXT, home_id TEXT, name TEXT, location TEXT, status TEXT,
             revision INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE price_observations (id TEXT, home_id TEXT, receipt_line_id TEXT,
             product_pack_id TEXT, store_id TEXT, currency TEXT, quantity TEXT, unit_price TEXT,
             line_total TEXT, observed_at TEXT, created_at TEXT, superseded_at TEXT)',
            'CREATE TABLE receipt_line_corrections (id TEXT PRIMARY KEY, home_id TEXT, receipt_id TEXT,
             receipt_line_id TEXT, request_hash TEXT, reason TEXT, receipt_revision INTEGER,
             original_line_json TEXT, next_line_json TEXT, actor_user_id TEXT, created_at TEXT,
             UNIQUE(home_id, receipt_id, receipt_revision))',
            'CREATE TABLE receipt_date_corrections (id TEXT PRIMARY KEY, home_id TEXT, receipt_id TEXT,
             original_purchase_date TEXT, purchase_date TEXT, reason TEXT, receipt_revision INTEGER,
             actor_user_id TEXT, created_at TEXT, UNIQUE(home_id, receipt_id, receipt_revision))',
            'CREATE TABLE stock_movements (id TEXT PRIMARY KEY, home_id TEXT, home_product_id TEXT,
             movement_type TEXT, quantity_delta TEXT, source_type TEXT, source_id TEXT, reason TEXT,
             actor_user_id TEXT, reversed_movement_id TEXT, occurred_at TEXT, created_at TEXT,
             UNIQUE(home_id, source_type, source_id, home_product_id))',
            'CREATE TABLE inventory_balances (home_id TEXT, home_product_id TEXT, quantity TEXT,
             last_movement_id TEXT, revision INTEGER, updated_at TEXT,
             PRIMARY KEY(home_id, home_product_id))',
            ] as $sql
        ) {
            $this->connection->executeStatement($sql);
        }
        \ProvidentiaTest\Support\InventoryChronologySchema::install($this->connection);
        $this->at = new DateTimeImmutable('2026-10-13T07:00:00Z');
        $this->store = new DbalPurchasingStore($this->connection);
        $ids = $this->createStub(UuidGenerator::class);
        $sequence = 1;
        $ids->method('generate')->willReturnCallback(static function () use (&$sequence): string {
            return sprintf('01912345-6789-7abc-8def-%012d', $sequence++);
        });
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(fn (): DateTimeImmutable => $this->at);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            fn (callable $work): mixed => $this->connection->transactional(
                static fn (Connection $connection): mixed => $work(),
            ),
        );
        $authorization = $this->createStub(HomePermissionAuthorizer::class);
        $authorization->method('requirePermission')->willReturnCallback(
            static function (AuthenticatedIdentity $identity, string $home): array {
                if ($identity->userId !== self::USER || $home !== self::HOME) {
                    throw new Problem(404, 'Not found', 'Home unavailable.');
                }
                return [];
            },
        );
        $ledger = new DbalInventoryStore($this->connection);
        $inventory = $this->createStub(InventoryMovementGateway::class);
        $inventory->method('recordApprovedInbound')->willReturnCallback(
            fn (string $actor, string $home, string $item, string $quantity, string $source,
                string $sourceId, string $reason, DateTimeImmutable $occurred): array => $ledger->appendMovement(
                    $ids->generate(),
                    $home,
                    $item,
                    'purchase-in',
                    $quantity,
                    $source,
                    $sourceId,
                    $reason,
                    $actor,
                    $occurred,
                    $this->at,
                ),
        );
        $inventory->method('recordPurchaseCorrection')->willReturnCallback(
            fn (string $actor, string $home, string $item, string $quantity, string $sourceId,
                string $reason, DateTimeImmutable $occurred): array => $ledger->appendMovement(
                    $ids->generate(),
                    $home,
                    $item,
                    'purchase-correction',
                    $quantity,
                    'receipt-line-correction',
                    $sourceId,
                    $reason,
                    $actor,
                    $occurred,
                    $this->at,
                ),
        );
        $inventory->method('correctPurchaseDate')->willReturnCallback(
            fn (string $actor, string $home, string $line, DateTimeImmutable $purchaseDate,
                string $correctionId, string $reason): array => $ledger->correctPurchaseDate(
                    $actor,
                    $home,
                    $line,
                    $purchaseDate,
                    $correctionId,
                    $reason,
                    $this->at,
                ),
        );
        $this->service = new PurchasingService($this->store, $inventory, $authorization, $ids, $clock, $transactions);
        $this->connection->insert('home_products', [
            'id' => self::ITEM, 'home_id' => self::HOME, 'product_id' => 'apples',
            'private_name' => 'Apples', 'unit' => 'kg', 'status' => 'active',
        ]);
        $this->connection->insert('units', ['id' => 'mass', 'dimension' => 'mass']);
        $this->connection->insert('product_packs', [
            'id' => self::PACK, 'product_id' => 'apples', 'unit_id' => 'mass',
            'normalized_base_amount' => '1000', 'original_pack_text' => '1 kg bag', 'status' => 'published',
        ]);
        $this->service->createReceipt(
            $this->identity(),
            self::HOME,
            null,
            '2026-10-13',
            'NAD',
            null,
            '',
            null,
            self::RECEIPT,
        );
    }

    public function testPurchasesImmediatelyAddToExistingBalanceAndCompletionDoesNotRepeatThem(): void
    {
        new DbalInventoryStore($this->connection)->appendMovement(
            '01912345-6789-7abc-8def-4123456789ab',
            self::HOME,
            self::ITEM,
            'adjustment',
            '2',
            'manual',
            'opening-stock',
            'Opening stock evidence',
            self::USER,
            $this->at->modify('-1 day'),
            $this->at,
        );
        $first = $this->purchase('4');
        self::assertSame('approved', $first['approvalStatus']);
        self::assertSame('4', $first['quantity']);
        self::assertSame('4', $first['stockQuantity']);
        self::assertSame('1', $first['stockQuantityPerPack']);
        self::assertSame(2, $first['receiptRevision']);
        self::assertSame('6', $this->balance());
        self::assertSame('draft', $this->service->receipt($this->identity(), self::HOME, self::RECEIPT)['status']);
        $this->connection->update('product_packs', [
            'normalized_base_amount' => '4000', 'original_pack_text' => '4 kg bag',
        ], ['id' => self::PACK]);
        $this->service->purchaseLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            2,
            self::ITEM,
            self::PACK,
            'Apples',
            '1',
            null,
            '40',
            null,
            'kg',
            '4',
        );
        self::assertSame('10', $this->balance());
        $receipt = $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 3);
        self::assertSame(0, $receipt['movements']);
        self::assertSame('10', $this->balance());
        self::assertSame(0, $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 3)['movements']);
        self::assertSame('2', (string) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'purchase-in'",
        ));
    }

    public function testRetryOfSameLineIdentifierCannotIncreaseStockTwice(): void
    {
        $first = $this->purchase('5');
        self::assertSame($first, $this->purchase('5'));
        self::assertSame('5', $this->balance());
        $this->expectException(Problem::class);
        $this->purchase('6');
    }

    public function testCorrectionsUseSnapshotAndPreserveOldMovementAndPriceEvidence(): void
    {
        $this->purchase('4');
        $this->connection->update('product_packs', ['normalized_base_amount' => '9000'], ['id' => self::PACK]);
        $result = $this->service->updateLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            self::LINE,
            ['rawDescription' => 'Apples', 'quantity' => '3', 'originalPackText' => '1 kg bag',
                'unitPrice' => '10', 'lineTotal' => '30'],
            1,
        );
        self::assertSame(2, $result['revision']);
        self::assertSame('3', $this->balance());
        self::assertSame(
            ['4', '-1'],
            $this->connection->fetchFirstColumn('SELECT quantity_delta FROM stock_movements'),
        );
        self::assertSame('2', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM price_observations'));
        self::assertSame('1', (string) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM price_observations WHERE superseded_at IS NOT NULL',
        ));
        $this->service->removeLine($this->identity(), self::HOME, self::RECEIPT, self::LINE, 2);
        self::assertSame('0', $this->balance());
        $line = $this->line();
        self::assertSame('removed', $line['approvalStatus']);
        self::assertSame(self::ITEM, $line['homeProductId']);
        self::assertSame(self::PACK, $line['packId']);
        self::assertSame('0', $line['stockQuantity']);
    }

    public function testCancellationReversesAllAppliedLinesAndBumpsReceiptOnce(): void
    {
        $this->purchase('4');
        $this->service->purchaseLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            2,
            self::ITEM,
            null,
            'Apples loose',
            '0.5',
            null,
            null,
            '5',
            'kg',
            '1',
        );
        self::assertSame('4.5', $this->balance());
        $result = $this->service->cancelReceipt($this->identity(), self::HOME, self::RECEIPT, 3);
        self::assertSame(4, $result['revision']);
        self::assertSame('0', $this->balance());
        self::assertSame('cancelled', $this->service->receipt($this->identity(), self::HOME, self::RECEIPT)['status']);
        $inbound = new DbalShoppingIntelligenceStore($this->connection)->inboundMovements(self::HOME, $this->at);
        self::assertSame(
            [],
            new DbalShoppingIntelligenceStore($this->connection)->purchaseMovements(self::HOME, $this->at),
        );
        self::assertSame(['0', '0'], array_map(
            static fn (array $row): string => \Providentia\Inventory\Domain\DecimalQuantity::delta(
                (string) $row['quantity'],
            )->toString(),
            $inbound,
        ));
    }

    public function testStoreCorrectionAfterCompletionDoesNotChangeStock(): void
    {
        $this->purchase('5');
        $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 2);
        $this->connection->insert('stores', [
            'id' => self::STORE, 'home_id' => self::HOME, 'name' => 'Market', 'status' => 'active', 'revision' => 1,
        ]);
        $this->service->updateReceipt($this->identity(), self::HOME, self::RECEIPT, [
            'storeId' => self::STORE, 'purchaseDate' => '2026-10-13', 'currency' => 'NAD',
            'totalAmount' => '50', 'notes' => 'Corrected store',
        ], 3);
        self::assertSame('5', $this->balance());
        self::assertSame(self::STORE, $this->service->receipt($this->identity(), self::HOME, self::RECEIPT)['storeId']);
        self::assertSame(self::STORE, $this->connection->fetchOne(
            'SELECT store_id FROM price_observations WHERE superseded_at IS NULL',
        ));
        self::assertNull($this->connection->fetchOne(
            'SELECT store_id FROM price_observations WHERE superseded_at IS NOT NULL',
        ));
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_movements'));
    }

    public function testAuditedDateCorrectionPreservesLogicalPurchaseAndSupersedesPriceEvidence(): void
    {
        $this->purchase('4');
        $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 2);
        $original = $this->service->receipt($this->identity(), self::HOME, self::RECEIPT);
        $result = $this->service->correctReceiptDate(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            '2026-10-10',
            'Corrected from original receipt',
            3,
        );
        self::assertSame(4, $result['revision']);
        $receipt = $this->service->receipt($this->identity(), self::HOME, self::RECEIPT);
        self::assertSame('2026-10-10', $receipt['purchaseDate']);
        self::assertSame($original['createdAt'], $receipt['createdAt']);
        self::assertSame($original['committedAt'], $receipt['committedAt']);
        self::assertSame($original['lines'], $receipt['lines']);
        self::assertSame('4', $this->balance());
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_movements'));
        self::assertSame('2026-10-13 00:00:00', $this->connection->fetchOne('SELECT occurred_at FROM stock_movements'));
        $history = $this->service->receiptDateCorrections($this->identity(), self::HOME, self::RECEIPT);
        self::assertCount(1, $history);
        self::assertSame('2026-10-13', $history[0]['originalPurchaseDate']);
        self::assertSame('2026-10-10', $history[0]['purchaseDate']);
        self::assertSame(self::USER, $history[0]['actorUserId']);
        self::assertSame(4, $history[0]['receiptRevision']);
        self::assertSame($this->at->format(DATE_ATOM), $history[0]['correctedAt']);
        self::assertSame('2026-10-10 00:00:00', $this->connection->fetchOne(
            'SELECT observed_at FROM price_observations WHERE superseded_at IS NULL',
        ));
        self::assertSame('2', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM price_observations'));

        try {
            $this->service->correctReceiptDate(
                $this->identity(),
                self::HOME,
                self::RECEIPT,
                '2026-10-09',
                'Stale device',
                3,
            );
            self::fail('A stale date correction must not overwrite another device.');
        } catch (Problem $error) {
            self::assertSame(409, $error->status);
        }
        self::assertCount(1, $this->store->receiptDateCorrections(self::HOME, self::RECEIPT));
        self::assertSame('2026-10-10', $this->receiptRecord()['purchaseDate']);
    }

    public function testNotesOnlyEditPreservesCorrectedDayCurrencyAndStock(): void
    {
        $this->purchase('4');
        $this->service->correctReceiptDate(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            '2026-10-10',
            'Shopping was earlier',
            2,
        );
        $this->service->updateReceipt($this->identity(), self::HOME, self::RECEIPT, [
            'storeId' => null, 'purchaseDate' => '2026-10-10', 'currency' => 'NAD',
            'totalAmount' => '40', 'notes' => 'Metadata only',
        ], 3);
        self::assertSame('4', $this->balance());
        self::assertSame('2026-10-10', $this->receiptRecord()['purchaseDate']);
        self::assertSame('2', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM price_observations'));
        try {
            $this->service->updateReceipt($this->identity(), self::HOME, self::RECEIPT, [
                'storeId' => null, 'purchaseDate' => '2026-10-10', 'currency' => 'USD',
                'totalAmount' => '40', 'notes' => 'Currency reinterpretation',
            ], 4);
            self::fail('Date correction must not unlock currency reinterpretation.');
        } catch (Problem $error) {
            self::assertSame(409, $error->status);
        }
        self::assertSame('NAD', $this->receiptRecord()['currency']);
    }

    public function testUnavailableOriginalMovementRollsBackDateAndPriceCorrectionsForReview(): void
    {
        $this->purchase('4');
        $this->connection->executeStatement('DELETE FROM stock_movements');
        try {
            $this->service->correctReceiptDate(
                $this->identity(),
                self::HOME,
                self::RECEIPT,
                '2026-10-10',
                'Original evidence is unavailable',
                2,
            );
            self::fail('Missing original movement must require review.');
        } catch (Problem $error) {
            self::assertSame(409, $error->status);
        }
        $receipt = $this->receiptRecord();
        self::assertSame('2026-10-13', $receipt['purchaseDate']);
        self::assertSame(2, $receipt['revision']);
        self::assertSame([], $this->store->receiptDateCorrections(self::HOME, self::RECEIPT));
        self::assertSame('1', (string) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM price_observations WHERE superseded_at IS NULL',
        ));
    }

    public function testDateCorrectionAcrossReliableCountReconcilesWithoutChangingPhysicalObservation(): void
    {
        foreach ([['opening', '2026-10-01 12:00:00', '2'], ['closing', '2026-10-11 12:00:00', '3']] as $count) {
            [$id, $observed, $quantity] = $count;
            $this->connection->insert('stock_count_sessions', [
                'id' => $id, 'home_id' => self::HOME, 'status' => 'closed',
                'scope_complete' => 1, 'reliability' => 'reliable',
                'observed_at' => $observed, 'closed_at' => $observed, 'updated_at' => $observed,
            ]);
            $this->connection->insert('stock_count_lines', [
                'id' => $id . '-line', 'home_id' => self::HOME, 'session_id' => $id,
                'home_product_id' => self::ITEM, 'quantity' => $quantity, 'stock_unit' => 'kg',
                'status' => 'confirmed', 'updated_at' => $observed,
            ]);
        }
        $this->purchase('4');
        self::assertSame('7', $this->balance());
        $this->service->correctReceiptDate(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            '2026-10-10',
            'Bought before the later physical count',
            2,
        );
        self::assertSame('3', $this->balance());
        self::assertSame('3', $this->connection->fetchOne(
            "SELECT quantity FROM stock_count_lines WHERE id = 'closing-line'",
        ));
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_movements'));
        self::assertSame('1', (string) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM stock_purchase_date_corrections',
        ));
        new DbalInventoryStore($this->connection)->rebuildBalances(self::HOME, $this->at);
        self::assertSame('3', $this->balance());
        $this->service->cancelReceipt($this->identity(), self::HOME, self::RECEIPT, 3);
        self::assertSame('3', $this->balance());
        self::assertSame(
            ['4', '-4'],
            $this->connection->fetchFirstColumn('SELECT quantity_delta FROM stock_movements'),
        );
    }

    public function testCommittedReceiptCancellationRetainsEvidenceAndReversesStockOnce(): void
    {
        $this->purchase('4');
        $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 2);
        $this->service->cancelReceipt($this->identity(), self::HOME, self::RECEIPT, 3);
        self::assertSame('0', $this->balance());
        self::assertSame('cancelled', $this->receiptRecord()['status']);
        self::assertSame(
            ['4', '-4'],
            $this->connection->fetchFirstColumn('SELECT quantity_delta FROM stock_movements'),
        );
        self::assertSame('0', (string) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM price_observations WHERE superseded_at IS NULL',
        ));
        $this->expectException(Problem::class);
        $this->service->cancelReceipt($this->identity(), self::HOME, self::RECEIPT, 3);
    }

    public function testPurchaseCalendarDateValidationIsIndependentOfHostTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        try {
            foreach (['Africa/Windhoek', 'America/New_York', 'Pacific/Kiritimati', 'UTC'] as $timezone) {
                date_default_timezone_set($timezone);
                $receipt = $this->service->createReceipt(
                    $this->identity(),
                    self::HOME,
                    null,
                    '2024-02-29',
                    'NAD',
                    null,
                    '',
                    null,
                );
                self::assertSame('2024-02-29', $receipt['purchaseDate']);
            }
            foreach (['2026-02-29', '2026-04-31', '2026-10-13T00:00:00+02:00', 'yesterday', '0000-01-01'] as $date) {
                try {
                    $this->service->createReceipt($this->identity(), self::HOME, null, $date, 'NAD', null, '', null);
                    self::fail('Invalid purchase calendar date accepted: ' . $date);
                } catch (Problem $error) {
                    self::assertSame(422, $error->status);
                }
            }
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testFuturePurchaseValidationUsesTheHomeCalendarAcrossUtcMidnight(): void
    {
        $this->connection->insert('homes', ['id' => self::HOME, 'default_timezone' => 'Africa/Windhoek']);
        $this->at = new DateTimeImmutable('2026-10-13T22:30:00Z');
        $receipt = $this->service->createReceipt(
            $this->identity(),
            self::HOME,
            null,
            '2026-10-14',
            'NAD',
            null,
            '',
            null,
        );
        self::assertSame('2026-10-14', $receipt['purchaseDate']);
        $this->connection->update('homes', ['default_timezone' => 'America/Los_Angeles'], ['id' => self::HOME]);
        $this->at = new DateTimeImmutable('2026-10-14T00:30:00Z');
        try {
            $this->service->createReceipt(
                $this->identity(),
                self::HOME,
                null,
                '2026-10-14',
                'NAD',
                null,
                '',
                null,
            );
            self::fail('Tomorrow in the household timezone must not be accepted as an actual purchase.');
        } catch (Problem $error) {
            self::assertSame(422, $error->status);
        }
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM receipts'));
    }

    public function testFutureDateCannotBeIntroducedByEditingCorrectionOrImportConfirmation(): void
    {
        $this->purchase('4');
        foreach (['metadata', 'correction'] as $workflow) {
            try {
                if ($workflow === 'metadata') {
                    $this->service->updateReceipt($this->identity(), self::HOME, self::RECEIPT, [
                        'storeId' => null, 'purchaseDate' => '2026-10-14', 'currency' => 'NAD',
                        'totalAmount' => '40', 'notes' => '',
                    ], 2);
                } else {
                    $this->service->correctReceiptDate(
                        $this->identity(),
                        self::HOME,
                        self::RECEIPT,
                        '2026-10-14',
                        'This is tomorrow',
                        2,
                    );
                }
                self::fail('A future correction must be rejected.');
            } catch (Problem $error) {
                self::assertSame(422, $error->status);
            }
        }
        self::assertSame('2026-10-13', $this->receiptRecord()['purchaseDate']);
        self::assertSame(2, $this->receiptRecord()['revision']);
        self::assertSame('4', $this->balance());
        $this->connection->update('receipts', ['purchase_date' => '2026-10-14'], ['id' => self::RECEIPT]);
        try {
            $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 2);
            self::fail('An imported or legacy future-dated draft cannot be confirmed.');
        } catch (Problem $error) {
            self::assertSame(422, $error->status);
        }
        // Retain existing legacy data and allow its day to be deliberately corrected.
        $this->service->correctReceiptDate(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            '2026-10-12',
            'Correct a legacy future date',
            2,
        );
        self::assertSame('2026-10-12', $this->receiptRecord()['purchaseDate']);
    }

    public function testWrongDimensionAndStaleRevisionDoNotLeavePartialPurchaseData(): void
    {
        $this->connection->update('units', ['dimension' => 'volume'], ['id' => 'mass']);
        try {
            $this->purchase('1');
            self::fail('Volume packs must not become kilograms.');
        } catch (Problem $error) {
            self::assertSame(422, $error->status);
        }
        self::assertSame([], $this->store->receiptLines(self::HOME, self::RECEIPT));
        self::assertSame(
            '1',
            (string) $this->service->receipt($this->identity(), self::HOME, self::RECEIPT)['revision'],
        );
        $this->connection->update('units', ['dimension' => 'mass'], ['id' => 'mass']);
        $this->purchase('1');
        try {
            $this->service->purchaseLine(
                $this->identity(),
                self::HOME,
                self::RECEIPT,
                1,
                self::ITEM,
                null,
                'Apples',
                '2',
                null,
                null,
                '20',
                'kg',
                '1',
            );
            self::fail('A stale receipt revision must conflict.');
        } catch (Problem $error) {
            self::assertSame(409, $error->status);
        }
        self::assertCount(1, $this->store->receiptLines(self::HOME, self::RECEIPT));
        self::assertSame('1', $this->balance());
    }

    public function testFractionalKilogramsRemainExactAndUnrepresentableConversionFails(): void
    {
        $this->connection->update('product_packs', ['normalized_base_amount' => '250'], ['id' => self::PACK]);
        self::assertSame('0.75', $this->purchase('3')['stockQuantity']);
        self::assertSame('0.75', $this->balance());
        $this->connection->update('product_packs', ['normalized_base_amount' => '0.00000001'], ['id' => self::PACK]);
        $this->expectException(Problem::class);
        $this->service->purchaseLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            2,
            self::ITEM,
            self::PACK,
            'Apples',
            '1',
            null,
            null,
            '20',
            'kg',
            '1',
        );
    }

    public function testPackReviewedImportWaitsForCompletionAndUsesTheConfirmedConversion(): void
    {
        $this->connection->update('product_packs', ['normalized_base_amount' => '4000'], ['id' => self::PACK]);
        $this->service->addLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            1,
            'Apples',
            '1',
            '4 kg bag',
            null,
            '40',
            self::LINE,
        );
        $this->service->approveLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            self::LINE,
            self::ITEM,
            1,
            self::PACK,
            'kg',
            '4',
        );
        self::assertNull($this->line()['stockQuantity']);
        self::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_movements'));
        $this->connection->update('product_packs', ['normalized_base_amount' => '9000'], ['id' => self::PACK]);
        $receipt = $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 3);
        self::assertSame('4', $this->balance());
        self::assertSame('1', $receipt['lines'][0]['quantity']);
        self::assertSame('4', $receipt['lines'][0]['stockQuantity']);
        self::assertSame('4', $receipt['lines'][0]['stockQuantityPerPack']);
    }

    public function testOfflineUnitAndPackChangesFailWithoutSilentlyChangingThePurchase(): void
    {
        $this->connection->update('home_products', ['unit' => 'g'], ['id' => self::ITEM]);
        try {
            $this->purchase('5');
            self::fail('A changed stock unit must conflict.');
        } catch (Problem $error) {
            self::assertSame(409, $error->status);
        }
        self::assertSame([], $this->store->receiptLines(self::HOME, self::RECEIPT));
        $this->connection->update('home_products', ['unit' => 'kg'], ['id' => self::ITEM]);
        $this->connection->update('product_packs', ['normalized_base_amount' => '2000'], ['id' => self::PACK]);
        try {
            $this->service->purchaseLine(
                $this->identity(),
                self::HOME,
                self::RECEIPT,
                1,
                self::ITEM,
                self::PACK,
                'Apples',
                '5',
                null,
                null,
                '50',
                'kg',
                '1',
                self::LINE,
            );
            self::fail('Changed canonical packaging must conflict.');
        } catch (Problem $error) {
            self::assertSame(409, $error->status);
        }
        self::assertSame([], $this->store->receiptLines(self::HOME, self::RECEIPT));
    }

    public function testUnitTrackedCatalogPackAcceptsCompatibleAlternativePackRatio(): void
    {
        $alternative = '01912345-6789-7abc-8def-3123456789ab';
        $this->connection->update('home_products', ['unit' => 'units', 'pack_id' => self::PACK], ['id' => self::ITEM]);
        $this->connection->insert('product_packs', [
            'id' => $alternative, 'product_id' => 'apples', 'unit_id' => 'mass',
            'normalized_base_amount' => '4000', 'original_pack_text' => '4 kg bag', 'status' => 'published',
        ]);
        $this->service->purchaseLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            1,
            self::ITEM,
            $alternative,
            'Apples',
            '1',
            null,
            null,
            '40',
            'units',
            '4',
            self::LINE,
        );
        self::assertSame('4', $this->balance());
    }

    public function testDirectHttpPurchaseIsIdempotentAndRejectsMalformedStockMetadata(): void
    {
        $body = [
            'id' => self::LINE, 'expectedReceiptRevision' => 1, 'homeProductId' => self::ITEM,
            'packId' => self::PACK, 'rawDescription' => 'Apples', 'quantity' => '5',
            'originalPackText' => null, 'unitPrice' => '10', 'lineTotal' => null,
            'unit' => 'kg', 'stockQuantityPerPack' => '1',
        ];
        $request = new ServerRequest()->withAttribute('homeId', self::HOME)
            ->withAttribute('receiptId', self::RECEIPT)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->identity())->withParsedBody($body);
        $handler = new PurchasingHandler($this->service, 'lines.purchase');
        self::assertSame(201, $handler->handle($request)->getStatusCode());
        self::assertSame(201, $handler->handle($request)->getStatusCode());
        self::assertSame('5', $this->balance());
        $body['stockQuantityPerPack'] = true;
        $this->expectException(Problem::class);
        $handler->handle($request->withParsedBody($body));
    }

    public function testDirectHttpDateCorrectionValidatesInputAndReturnsAuditableHistory(): void
    {
        $this->purchase('4');
        $body = ['purchaseDate' => '2026-10-10', 'reason' => 'Verified purchase day', 'expectedRevision' => 2];
        $request = new ServerRequest()->withAttribute('homeId', self::HOME)
            ->withAttribute('receiptId', self::RECEIPT)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->identity())->withParsedBody($body);
        $handler = new PurchasingHandler($this->service, 'date-corrections.create');
        self::assertSame(201, $handler->handle($request)->getStatusCode());
        $response = new PurchasingHandler($this->service, 'date-corrections.list')->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $history = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2026-10-13', $history['data'][0]['originalPurchaseDate']);
        self::assertSame('2026-10-10', $history['data'][0]['purchaseDate']);
        $body['expectedRevision'] = '3';
        $this->expectException(Problem::class);
        $handler->handle($request->withParsedBody($body));
    }

    public function testFinishedReceiptCompletesPendingAndOmittedPurchasesOnceWithoutReplayingAppliedLine(): void
    {
        $pending = '01912345-6789-7abc-8def-6123456789ab';
        $omitted = '01912345-6789-7abc-8def-7123456789ab';
        $this->purchase('4');
        $this->service->addLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            2,
            'Unidentified apples',
            '1',
            null,
            null,
            '10',
            $pending
        );
        $this->service->unresolveLine($this->identity(), self::HOME, self::RECEIPT, $pending, 1);
        $finished = $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 4);
        self::assertSame('available', $finished['lineCorrectionState']);
        $fields = $this->completionFields(2);
        $resolved = $this->service->correctFinishedLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            $pending,
            5,
            $fields,
        );
        self::assertSame(3, $resolved['revision']);
        self::assertSame(6, $resolved['receiptRevision']);
        self::assertSame('approved', $resolved['approvalStatus']);
        self::assertSame('5.5', $this->balance());
        self::assertSame($resolved, $this->service->correctFinishedLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            $pending,
            5,
            $fields,
        ));
        $missingFields = $this->completionFields(null);
        $missingFields['correctionId'] = '01912345-6789-7abc-8def-8123456789ab';
        $missingFields['quantity'] = '0.25';
        $missingFields['lineTotal'] = '2';
        $missing = $this->service->correctFinishedLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            $omitted,
            6,
            $missingFields,
        );
        self::assertSame(1, $missing['revision']);
        self::assertSame(7, $missing['receiptRevision']);
        self::assertSame('5.75', $this->balance());
        self::assertSame(1, $this->line()['revision']);
        self::assertSame('4', $this->line()['stockQuantity']);
        self::assertSame('committed', $this->receiptRecord()['status']);
        self::assertSame('2026-10-13 07:00:00', $this->receiptRecord()['committedAt']);
        self::assertSame(0, $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 7)['movements']);
        self::assertSame('3', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_movements'));
        self::assertSame('3', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM price_observations'));
        $history = $this->service->receiptLineCorrections($this->identity(), self::HOME, self::RECEIPT);
        self::assertCount(2, $history);
        self::assertSame('unresolved', $history[0]['originalLine']['approvalStatus']);
        self::assertSame($resolved['id'], $history[0]['nextLine']['id']);
        self::assertSame('1.5', $history[0]['nextLine']['stockQuantity']);
        self::assertNull($history[1]['originalLine']);
        self::assertArrayNotHasKey('requestHash', $history[0]);
        // A frozen retry acknowledgement remains identical after later audited date changes.
        $this->service->correctReceiptDate(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            '2026-10-10',
            'Correct purchase calendar day',
            7
        );
        self::assertSame($resolved, $this->service->correctFinishedLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            $pending,
            5,
            $fields,
        ));
        self::assertSame('5.75', $this->balance());
        $this->service->cancelReceipt($this->identity(), self::HOME, self::RECEIPT, 8);
        self::assertSame('0', $this->balance());
        self::assertSame('immutable', $this->receiptRecord()['lineCorrectionState']);
        self::assertCount(2, $this->service->receiptLineCorrections($this->identity(), self::HOME, self::RECEIPT));
    }

    public function testFinishedCorrectionRejectsStaleSnapshotsChangedIntentAndAppliedLines(): void
    {
        $this->purchase('4');
        $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 2);
        $omitted = '01912345-6789-7abc-8def-7123456789ab';
        $fields = $this->completionFields(null);
        foreach ([[2, null], [3, 1]] as [$receiptRevision, $lineRevision]) {
            $invalid = [...$fields, 'expectedLineRevision' => $lineRevision];
            try {
                $this->service->correctFinishedLine(
                    $this->identity(),
                    self::HOME,
                    self::RECEIPT,
                    $omitted,
                    $receiptRevision,
                    $invalid
                );
                self::fail('Stale or nonexistent line snapshots must conflict.');
            } catch (Problem $problem) {
                self::assertSame(409, $problem->status);
            }
        }
        try {
            $this->service->correctFinishedLine(
                $this->identity(),
                self::HOME,
                self::RECEIPT,
                self::LINE,
                3,
                $this->completionFields(1)
            );
            self::fail('Applied lines must use their existing correction workflow.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
        }
        $this->service->correctFinishedLine($this->identity(), self::HOME, self::RECEIPT, $omitted, 3, $fields);
        try {
            $this->service->correctFinishedLine(
                $this->identity(),
                self::HOME,
                self::RECEIPT,
                $omitted,
                3,
                [...$fields, 'quantity' => '2']
            );
            self::fail('The correction ID cannot be reused with new intent.');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
        }
        self::assertSame('5.5', $this->balance());
        self::assertSame(4, $this->receiptRecord()['revision']);
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM receipt_line_corrections'));
    }

    public function testInterruptedFinishedCorrectionRollsBackReceiptStockPriceAndAuditTogether(): void
    {
        $this->purchase('4');
        $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 2);
        $omitted = '01912345-6789-7abc-8def-7123456789ab';
        $this->connection->executeStatement("CREATE TRIGGER fail_correction_audit
            BEFORE INSERT ON receipt_line_corrections BEGIN SELECT RAISE(ABORT, 'synthetic interruption'); END");
        try {
            $this->service->correctFinishedLine(
                $this->identity(),
                self::HOME,
                self::RECEIPT,
                $omitted,
                3,
                $this->completionFields(null)
            );
            self::fail('The injected audit failure must abort the entire operation.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('synthetic interruption', $error->getMessage());
        }
        self::assertSame('4', $this->balance());
        self::assertSame(3, $this->receiptRecord()['revision']);
        self::assertSame('committed', $this->receiptRecord()['status']);
        self::assertNull($this->store->receiptLine(self::HOME, self::RECEIPT, $omitted));
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM price_observations'));
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_movements'));
        self::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM receipt_line_corrections'));
        $this->connection->executeStatement('DROP TRIGGER fail_correction_audit');
        $this->service->correctFinishedLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            $omitted,
            3,
            $this->completionFields(null)
        );
        self::assertSame('5.5', $this->balance());
    }

    public function testFinishedCorrectionHttpUsesStrictTypesAndPreservesUnknownPrice(): void
    {
        $pending = $this->service->addLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            1,
            'Unresolved existing item',
            '1',
            null,
            null,
            '10'
        );
        $this->service->unresolveLine($this->identity(), self::HOME, self::RECEIPT, $pending['id'], 1);
        $this->service->commit($this->identity(), self::HOME, self::RECEIPT, 3);
        $line = '01912345-6789-7abc-8def-7123456789ab';
        $body = [...$this->completionFields(null), 'lineId' => $line, 'expectedReceiptRevision' => 4,
            'unitPrice' => null, 'lineTotal' => null];
        $handler = new PurchasingHandler($this->service, 'line-corrections.create');
        $request = new ServerRequest()->withAttribute(
            BearerAuthenticationMiddleware::ATTRIBUTE,
            $this->identity()
        )->withAttribute('homeId', self::HOME)->withAttribute('receiptId', self::RECEIPT);
        $response = $handler->handle($request->withParsedBody($body));
        self::assertSame(201, $response->getStatusCode());
        $result = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('approved', $result['approvalStatus']);
        self::assertNull($result['unitPrice']);
        self::assertNull($result['lineTotal']);
        self::assertSame('1.5', $this->balance());
        self::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM price_observations'));
        $history = new PurchasingHandler($this->service, 'line-corrections.list')->handle($request);
        self::assertCount(1, json_decode((string) $history->getBody(), true, 512, JSON_THROW_ON_ERROR)['data']);
        $this->expectException(\Providentia\SharedKernel\Http\HttpProblem::class);
        $handler->handle($request->withParsedBody([...$body, 'expectedReceiptRevision' => '2']));
    }

    /** @return array<string, mixed> */
    private function completionFields(?int $revision): array
    {
        return [
            'correctionId' => '01912345-6789-7abc-8def-5123456789ab',
            'expectedLineRevision' => $revision, 'reason' => 'Verified omitted or unresolved purchase',
            'homeProductId' => self::ITEM, 'packId' => self::PACK, 'rawDescription' => 'Apples confirmed',
            'quantity' => '1.5', 'originalPackText' => '1 kg bag', 'unitPrice' => '10', 'lineTotal' => '14',
            'unit' => 'kg', 'stockQuantityPerPack' => '1',
        ];
    }

    /** @return array<string, mixed> */
    private function purchase(string $quantity): array
    {
        return $this->service->purchaseLine(
            $this->identity(),
            self::HOME,
            self::RECEIPT,
            1,
            self::ITEM,
            self::PACK,
            'Apples',
            $quantity,
            null,
            '10',
            null,
            'kg',
            (string) \Brick\Math\BigDecimal::of((string) $this->connection->fetchOne(
                'SELECT normalized_base_amount FROM product_packs WHERE id = ?',
                [self::PACK],
            ))->multipliedBy('0.001')->strippedOfTrailingZeros(),
            self::LINE,
        );
    }

    /** @return array<string, mixed> */
    private function line(): array
    {
        $line = $this->store->receiptLine(self::HOME, self::RECEIPT, self::LINE);
        self::assertNotNull($line);
        return $line;
    }

    /** @return array<string, mixed> */
    private function receiptRecord(): array
    {
        $receipt = $this->store->receipt(self::HOME, self::RECEIPT);
        self::assertNotNull($receipt);
        return $receipt;
    }

    private function balance(): string
    {
        return \Providentia\Inventory\Domain\DecimalQuantity::delta((string) $this->connection->fetchOne(
            'SELECT quantity FROM inventory_balances WHERE home_product_id = ?',
            [self::ITEM],
        ))->toString();
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(self::USER, 'session', 'device', self::HOME, []);
    }
}
