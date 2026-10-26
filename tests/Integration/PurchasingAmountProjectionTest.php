<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuditRecorder;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryAnalyticsReader;
use Providentia\Purchasing\Infrastructure\Doctrine\DbalPurchasingStore;
use Providentia\Reporting\Application\HomeReportService;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingIntelligenceReader;
use ProvidentiaTest\Support\AccessFixture;

final class PurchasingAmountProjectionTest extends TestCase
{
    private const HOME = '01912345-6789-7abc-8def-0123456789ab';
    private const USER = '01912345-6789-7abc-9def-0123456789ab';
    private Connection $connection;
    private DbalPurchasingStore $store;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (
            [
            'CREATE TABLE receipts (id TEXT PRIMARY KEY, home_id TEXT, store_id TEXT,
             purchase_date TEXT, currency TEXT, total_amount TEXT, status TEXT)',
            'CREATE TABLE receipt_lines (id TEXT PRIMARY KEY, home_id TEXT, receipt_id TEXT,
             quantity TEXT, unit_price TEXT, line_total TEXT, approval_status TEXT)',
            'CREATE TABLE stores (id TEXT, home_id TEXT, name TEXT)',
            ] as $sql
        ) {
            $this->connection->executeStatement($sql);
        }
        $this->connection->insert('stores', ['id' => 'shop', 'home_id' => self::HOME, 'name' => 'Synthetic shop']);
        $this->store = new DbalPurchasingStore($this->connection);
        $this->today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    }

    public function testSummaryAndReportShareDeclaredDerivedAndCurrencySemantics(): void
    {
        $this->receipt('declared', 'NAD', '20.00');
        $this->line('declared', 'discount', '3', '10', '25');
        $this->receipt('derived', 'NAD', null);
        $this->line('derived', 'line-discount', '3', '10', '25');
        $this->line('derived', 'derived-half', '0.5', '0.01', null);
        $this->line('derived', 'removed', '1', null, '1000', 'removed');
        $this->receipt('cancelled', 'NAD', '900', 'cancelled');
        $this->line('cancelled', 'cancelled-line', '1', null, '900');
        $this->receipt('foreign', 'NAD', '700', 'committed', 'other-home');
        $this->line('derived', 'foreign-line', '1', null, '800', 'approved', 'other-home');
        $summary = $this->store->summary(self::HOME, 90);
        self::assertSame('45.01', $summary['spend']);
        self::assertSame('NAD', $summary['currency']);
        self::assertSame(2, $summary['receiptCount']);
        self::assertSame(3, $summary['lineCount']);
        $report = $this->report();
        self::assertCount(1, $report['data']);
        self::assertSame($summary['spend'], $report['data'][0]['total']);
        self::assertSame('mixed', $report['data'][0]['amountSource']);
        self::assertSame(1, $report['data'][0]['declaredReceiptCount']);
        self::assertSame(1, $report['data'][0]['derivedReceiptCount']);

        $this->receipt('zar', 'ZAR', '0');
        $summary = $this->store->summary(self::HOME, 90);
        self::assertNull($summary['spend']);
        self::assertNull($summary['currency']);
        self::assertTrue($summary['pricingComplete']);
        self::assertSame(['NAD', 'ZAR'], array_column($summary['currencyTotals'], 'currency'));
        self::assertSame(['45.01', '0'], array_column($summary['currencyTotals'], 'spend'));
        self::assertSame(['45.01', '0'], array_column($this->report()['data'], 'total'));
    }

    public function testUnknownPartialAndZeroAmountsSurviveProjectionAndReadOnlyRepeats(): void
    {
        $this->receipt('partial', 'NAD', null);
        $this->line('partial', 'known', '2', '3.33', null, 'unresolved');
        $this->line('partial', 'unknown', '1', null, null, 'unresolved');
        $this->receipt('unknown', 'NAD', null);
        $this->line('unknown', 'unpriced', '1', null, null);
        $this->receipt('empty', 'NAD', null);
        $this->receipt('zero', 'NAD', null);
        $this->line('zero', 'free', '2', '0', null);
        $before = $this->connection->fetchAllAssociative('SELECT * FROM receipts ORDER BY id');
        $facts = $this->facts();
        self::assertSame('partial', $facts['partial']['amountSource']);
        self::assertNull($facts['partial']['totalAmount']);
        self::assertSame('6.66', $facts['partial']['knownSubtotal']);
        self::assertSame('unknown', $facts['unknown']['amountSource']);
        self::assertNull($facts['unknown']['knownSubtotal']);
        self::assertSame('unknown', $facts['empty']['amountSource']);
        self::assertSame('0', $facts['zero']['totalAmount']);
        self::assertTrue($facts['zero']['pricingComplete']);
        $summary = $this->store->summary(self::HOME, 90);
        $row = $this->report()['data'][0];
        self::assertNull($summary['spend']);
        self::assertNull($row['total']);
        self::assertSame('6.66', $row['knownSubtotal']);
        self::assertFalse($row['pricingComplete']);
        self::assertSame(1, $row['completeReceiptCount']);
        self::assertSame(1, $row['partialReceiptCount']);
        self::assertSame(2, $row['unknownReceiptCount']);
        self::assertSame($row['knownSubtotal'], $summary['currencyTotals'][0]['knownSubtotal']);
        self::assertSame($row, $this->report()['data'][0]);
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM receipts ORDER BY id'));
    }

    public function testCorrectedDatesAndAmountsAffectCurrentRangeWithoutResurrectingRemovedLines(): void
    {
        $this->receipt('corrected', 'NAD', null);
        $this->line('corrected', 'current', '1', null, '5');
        self::assertSame('5', $this->store->summary(self::HOME, 90)['spend']);
        $this->connection->update('receipt_lines', ['line_total' => '4.50'], ['id' => 'current']);
        self::assertSame('4.5', $this->report()['data'][0]['total']);
        $this->connection->update('receipts', [
            'purchase_date' => $this->today->modify('-100 days')->format('Y-m-d'),
        ], ['id' => 'corrected']);
        self::assertSame([], $this->report()['data']);
        self::assertSame('0', $this->store->summary(self::HOME, 90)['spend']);
        $this->connection->update(
            'receipts',
            ['purchase_date' => $this->today->format('Y-m-d')],
            ['id' => 'corrected'],
        );
        $this->connection->update('receipt_lines', ['approval_status' => 'removed'], ['id' => 'current']);
        self::assertNull($this->report()['data'][0]['total']);
        self::assertSame('unknown', $this->report()['data'][0]['amountSource']);
        $this->connection->update('receipts', ['total_amount' => '0'], ['id' => 'corrected']);
        self::assertSame('0', $this->report()['data'][0]['total']);
    }

    /** @return array<string, array<string, mixed>> */
    private function facts(): array
    {
        $facts = [];
        foreach ($this->store->purchaseFacts(self::HOME, $this->today, $this->today) as $fact) {
            $facts[(string) $fact['id']] = $fact;
        }
        return $facts;
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn(['status' => 'active', 'role' => HomeAuthorization::VIEWER]);
        $homes->method('permissionDecision')->willReturn(null);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->today->setTime(12, 0));
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01912345-6789-7abc-adef-0123456789ab');
        return new HomeReportService(
            new HomeAuthorization($homes, AccessFixture::create()),
            $this->createStub(InventoryAnalyticsReader::class),
            $this->store,
            $this->createStub(ShoppingIntelligenceReader::class),
            $this->createStub(HomeAuditRecorder::class),
            $ids,
            $clock,
        )->report(
            new AuthenticatedIdentity(self::USER, 'session', 'device', self::HOME, []),
            self::HOME,
            'purchases',
            $this->today->format('Y-m-d'),
            $this->today->format('Y-m-d'),
        );
    }

    private function receipt(
        string $id,
        string $currency,
        ?string $total,
        string $status = 'committed',
        string $home = self::HOME,
    ): void {
        $this->connection->insert('receipts', [
            'id' => $id, 'home_id' => $home, 'store_id' => 'shop',
            'purchase_date' => $this->today->format('Y-m-d'),
            'currency' => $currency, 'total_amount' => $total, 'status' => $status,
        ]);
    }

    private function line(
        string $receipt,
        string $id,
        string $quantity,
        ?string $unitPrice,
        ?string $total,
        string $status = 'approved',
        string $home = self::HOME,
    ): void {
        $this->connection->insert('receipt_lines', [
            'id' => $id, 'home_id' => $home, 'receipt_id' => $receipt,
            'quantity' => $quantity, 'unit_price' => $unitPrice,
            'line_total' => $total, 'approval_status' => $status,
        ]);
    }
}
