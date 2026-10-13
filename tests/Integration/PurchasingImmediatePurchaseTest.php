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
        $this->at = new DateTimeImmutable('2026-10-13T07:00:00Z');
        $this->store = new DbalPurchasingStore($this->connection);
        $ids = $this->createStub(UuidGenerator::class);
        $sequence = 1;
        $ids->method('generate')->willReturnCallback(static function () use (&$sequence): string {
            return sprintf('01912345-6789-7abc-8def-%012d', $sequence++);
        });
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->at);
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
        $this->connection->insert('inventory_balances', [
            'home_id' => self::HOME, 'home_product_id' => self::ITEM, 'quantity' => '2', 'revision' => 1,
        ]);
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
        self::assertSame('2', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_movements'));
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
