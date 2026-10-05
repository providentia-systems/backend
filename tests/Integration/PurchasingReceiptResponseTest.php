<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomePermission;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Inventory\Application\InventoryMovementGateway;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Purchasing\Http\PurchasingHandler;
use Providentia\Purchasing\Infrastructure\Doctrine\DbalPurchasingStore;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class PurchasingReceiptResponseTest extends TestCase
{
    private const HOME = '01912345-6789-7abc-8def-0123456789ab';
    private const RECEIPT = '01912345-6789-7abc-9def-0123456789ab';
    private const LINE = '01912345-6789-7abc-adef-0123456789ab';
    private const PRODUCT = '01912345-6789-7abc-bdef-0123456789ab';
    private const USER = '01912345-6789-7abc-cdef-0123456789ab';

    private Connection $connection;
    private DbalPurchasingStore $store;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (
            [
                'CREATE TABLE receipts (id TEXT PRIMARY KEY, home_id TEXT, store_id TEXT,
                 purchase_date TEXT, currency TEXT, total_amount DECIMAL(18, 2), status TEXT,
                 source TEXT, source_reference TEXT, notes TEXT, revision INTEGER,
                 created_by_user_id TEXT, committed_at TEXT, created_at TEXT, updated_at TEXT)',
                'CREATE TABLE receipt_lines (id TEXT PRIMARY KEY, home_id TEXT, receipt_id TEXT,
                 line_number INTEGER, raw_description TEXT, quantity DECIMAL(18, 8), original_pack_text TEXT,
                 unit_price DECIMAL(18, 2), line_total DECIMAL(18, 2), home_product_id TEXT,
                 approval_status TEXT, revision INTEGER, created_at TEXT, updated_at TEXT)',
                'CREATE TABLE stores (id TEXT, home_id TEXT, name TEXT)',
                'CREATE TABLE home_products (id TEXT, home_id TEXT, product_id TEXT, pack_id TEXT,
                 private_name TEXT, status TEXT)',
                'CREATE TABLE products (id TEXT, canonical_name TEXT)',
                'CREATE TABLE receipt_line_matches (home_id TEXT, receipt_line_id TEXT, status TEXT)',
                'CREATE TABLE price_observations (id TEXT, home_id TEXT, receipt_line_id TEXT,
                 product_pack_id TEXT, store_id TEXT, currency TEXT, quantity DECIMAL(18, 8),
                 unit_price DECIMAL(18, 2), line_total DECIMAL(18, 2), observed_at TEXT, created_at TEXT)',
            ] as $sql
        ) {
            $this->connection->executeStatement($sql);
        }
        $this->store = new DbalPurchasingStore($this->connection);
        $this->at = new DateTimeImmutable('2026-10-05T12:00:00Z');
    }

    public function testHttpWriteAcknowledgementsReturnResourcesWithoutRequiringReadPermission(): void
    {
        $service = $this->service(HomePermission::PURCHASES_WRITE);
        $created = $this->request($service, 'create', [
            'purchaseDate' => '2026-10-05',
            'currency' => 'NAD',
            'totalAmount' => '12.50',
            'notes' => 'Receipt review',
        ], 201);
        $this->assertReceipt($created, 'draft', 1);
        self::assertSame('12.5', $created['totalAmount']);
        self::assertSame('Receipt review', $created['notes']);
        self::assertSame([], $created['lines']);

        $line = $this->request($service, 'lines.create', [
            'expectedReceiptRevision' => 1,
            'rawDescription' => 'Pantry item',
            'quantity' => '0.00000001',
            'lineTotal' => '12.50',
        ], 201);
        $this->assertLine($line);
        self::assertSame('unreviewed', $line['approvalStatus']);
        self::assertSame(1, $line['revision']);
        self::assertNull($line['unitPrice']);
        self::assertSame('12.5', $line['lineTotal']);

        $this->request($service, 'lines.unresolve', ['expectedRevision' => 1]);
        $committed = $this->request($service, 'commit', ['expectedRevision' => 3]);
        $this->assertReceipt($committed, 'committed', 4);
        self::assertSame(self::RECEIPT, $committed['receiptId']);
        self::assertSame(0, $committed['movements']);
        self::assertSame('unresolved', $committed['lines'][0]['approvalStatus']);
        $this->assertLine($committed['lines'][0]);
        self::assertSame($committed, $this->request($service, 'commit', ['expectedRevision' => 3]));
    }

    public function testHttpReadAndHistoryProjectHomeAndDecimalStringsFromNumericDatabaseColumns(): void
    {
        $this->seedReceipt();
        $service = $this->service(HomePermission::PURCHASES_READ);
        $receipt = $this->request($service, 'get');
        $this->assertReceipt($receipt, 'draft', 2);
        self::assertSame('12.5', $receipt['totalAmount']);
        $this->assertLine($receipt['lines'][0]);
        self::assertSame('12.5', $receipt['lines'][0]['unitPrice']);
        self::assertSame('12.5', $receipt['lines'][0]['lineTotal']);

        $history = $this->request($service, 'history');
        self::assertCount(1, $history['data']);
        $this->assertReceipt($history['data'][0], 'draft', 2);
        self::assertSame('12.5', $history['data'][0]['totalAmount']);
        self::assertSame(1, $history['data'][0]['lineCount']);

        $this->connection->update('receipts', ['total_amount' => null], ['id' => self::RECEIPT]);
        $this->connection->update('receipt_lines', ['unit_price' => null, 'line_total' => null], ['id' => self::LINE]);
        self::assertNull($this->request($service, 'get')['totalAmount']);
        self::assertNull($this->request($service, 'history')['data'][0]['totalAmount']);
        $line = $this->store->receiptLine(self::HOME, self::RECEIPT, self::LINE);
        self::assertNotNull($line);
        self::assertNull($line['unitPrice']);
        self::assertNull($this->store->receiptLines(self::HOME, self::RECEIPT)[0]['lineTotal']);
        self::assertNull($this->store->receipt('other-home', self::RECEIPT));
        self::assertSame([], $this->store->receipts('other-home', null, null, null, 10, 0));
    }

    public function testApprovalAndCommitPublishDecimalStringsFromPersistedRowsInsideTransaction(): void
    {
        $this->seedReceipt();
        $this->connection->insert('home_products', [
            'id' => self::PRODUCT,
            'home_id' => self::HOME,
            'private_name' => 'Pantry item',
            'status' => 'active',
        ]);
        $published = [];
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::exactly(3))->method('put')->willReturnCallback(
            function (
                string $home,
                ?string $actor,
                string $type,
                string $id,
                int $revision,
                array $representation,
            ) use (&$published): int {
                self::assertTrue($this->connection->isTransactionActive());
                $published[] = [$type, $revision, $representation];

                return count($published);
            },
        );
        $inventory = $this->createMock(InventoryMovementGateway::class);
        $inventory->expects(self::once())->method('recordApprovedInbound')->with(
            self::USER,
            self::HOME,
            self::PRODUCT,
            '0.00000001',
            'receipt-line',
            self::LINE,
            'Approved receipt line',
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn(['id' => 'movement']);
        $service = $this->service(HomePermission::PURCHASES_WRITE, $changes, $inventory);
        $service->approveLine($this->identity(), self::HOME, self::RECEIPT, self::LINE, self::PRODUCT, 1);
        $receipt = $service->commit($this->identity(), self::HOME, self::RECEIPT, 3);
        $this->assertReceipt($receipt, 'committed', 4);
        self::assertSame(1, $receipt['movements']);
        self::assertSame('purchasing-receipt-line', $published[0][0]);
        self::assertSame(2, $published[0][1]);
        self::assertSame('0.00000001', $published[0][2]['quantity']);
        self::assertSame('12.5', $published[0][2]['unitPrice']);
        self::assertSame('12.5', $published[0][2]['lineTotal']);
        self::assertSame('12.5', $published[1][2]['totalAmount']);
        self::assertSame('purchasing-receipt', $published[2][0]);
        self::assertSame(4, $published[2][1]);
        self::assertSame('committed', $published[2][2]['status']);
        self::assertSame('12.5', $published[2][2]['totalAmount']);
    }

    public function testOptionalHistoryFiltersKeepDateBoundsAndTenantIsolation(): void
    {
        $this->seedReceipt();
        foreach ([[null, null], ['', ''], ['2026-10-05', null], [null, '2026-10-05']] as [$from, $to]) {
            self::assertCount(1, $this->store->receipts(self::HOME, $from, $to, null, 10, 0));
        }
        self::assertSame([], $this->store->receipts(self::HOME, '2026-10-06', null, null, 10, 0));
        self::assertSame([], $this->store->receipts(self::HOME, null, '2026-10-04', null, 10, 0));
        self::assertSame([], $this->store->receipts(self::HOME, null, null, 'other-store', 10, 0));
        self::assertSame([], $this->store->receipts('other-home', null, null, null, 10, 0));
    }

    private function seedReceipt(): void
    {
        $this->store->createReceipt(
            self::RECEIPT,
            self::HOME,
            null,
            '2026-10-05',
            'NAD',
            '12.50',
            'manual',
            null,
            '',
            self::USER,
            $this->at,
        );
        self::assertTrue($this->store->addReceiptLine(
            self::LINE,
            self::HOME,
            self::RECEIPT,
            1,
            1,
            'Pantry item',
            '0.00000001',
            null,
            '12.50',
            '12.50',
            $this->at,
        ));
    }

    private function service(
        string $permission,
        ?ChangeFeedWriter $changes = null,
        ?InventoryMovementGateway $inventory = null,
    ): PurchasingService {
        $authorization = $this->createStub(HomePermissionAuthorizer::class);
        $authorization->method('requirePermission')->willReturnCallback(
            static function (
                AuthenticatedIdentity $identity,
                string $home,
                string $requested,
            ) use ($permission): array {
                self::assertSame(self::HOME, $home);
                self::assertSame($permission, $requested);

                return [];
            },
        );
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->at);
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturnOnConsecutiveCalls(self::RECEIPT, self::LINE);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            fn(callable $operation): mixed => $this->connection->transactional(static fn(): mixed => $operation()),
        );

        return new PurchasingService(
            $this->store,
            $inventory ?? $this->createStub(InventoryMovementGateway::class),
            $authorization,
            $ids,
            $clock,
            $transactions,
            $changes,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(PurchasingService $service, string $action, array $body = [], int $status = 200): array
    {
        $request = new ServerRequest()->withAttribute('homeId', self::HOME)
            ->withAttribute('receiptId', self::RECEIPT)
            ->withAttribute('lineId', self::LINE)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->identity())
            ->withParsedBody($body);
        $response = new PurchasingHandler($service, $action)->handle($request);
        self::assertSame($status, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $receipt */
    private function assertReceipt(array $receipt, string $status, int $revision): void
    {
        self::assertSame(self::RECEIPT, $receipt['id']);
        self::assertSame(self::HOME, $receipt['homeId']);
        self::assertSame('2026-10-05', $receipt['purchaseDate']);
        self::assertSame('NAD', $receipt['currency']);
        self::assertSame('manual', $receipt['source']);
        self::assertSame($status, $receipt['status']);
        self::assertSame($revision, $receipt['revision']);
    }

    /** @param array<string, mixed> $line */
    private function assertLine(array $line): void
    {
        self::assertSame(self::LINE, $line['id']);
        self::assertSame(self::RECEIPT, $line['receiptId']);
        self::assertSame(1, $line['lineNumber']);
        self::assertSame('Pantry item', $line['rawDescription']);
        self::assertSame('0.00000001', $line['quantity']);
        self::assertIsInt($line['revision']);
        self::assertIsString($line['approvalStatus']);
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(self::USER, 'session', 'device', self::HOME, []);
    }
}
