<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Reporting;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuditRecorder;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryAnalyticsReader;
use Providentia\Purchasing\Application\PurchaseAnalyticsReader;
use Providentia\Reporting\Application\HomeReportService;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingIntelligenceReader;

final class HomeReportServiceTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';

    public function testPurchaseTotalsRemainSeparatedByCurrencyAndAreAudited(): void
    {
        $purchases = $this->createMock(PurchaseAnalyticsReader::class);
        $purchases->expects(self::once())
            ->method('purchaseFacts')
            ->with(
                self::HOME_ID,
                self::callback(static fn (DateTimeImmutable $date): bool => $date->format('Y-m-d') === '2026-07-01'),
                self::callback(static fn (DateTimeImmutable $date): bool => $date->format('Y-m-d') === '2026-07-31'),
            )
            ->willReturn([
                $this->purchase('NAD', '10.25'),
                $this->purchase('NAD', '2.25'),
                $this->purchase('ZAR', '99.00'),
            ]);
        $audit = $this->createMock(HomeAuditRecorder::class);
        $audit->expects(self::once())
            ->method('recordAudit')
            ->with(
                self::anything(),
                self::USER_ID,
                'report.generated',
                'home_report',
                'purchases',
                self::HOME_ID,
                self::stringContains('"from":"2026-07-01"'),
                self::isInstanceOf(DateTimeImmutable::class),
            );

        $report = $this->service($this->homeStore(HomeAuthorization::VIEWER), $purchases, $audit)->report(
            $this->identity(),
            self::HOME_ID,
            'purchases',
            '2026-07-01',
            '2026-07-31',
        );

        self::assertSame('totals-are-never-combined-across-currencies', $report['currencyPolicy']);
        self::assertCount(2, $report['data']);
        self::assertSame('NAD', $report['data'][0]['currency']);
        self::assertSame('12.5', $report['data'][0]['total']);
        self::assertSame(2, $report['data'][0]['receiptCount']);
        self::assertSame('ZAR', $report['data'][1]['currency']);
        self::assertSame('99', $report['data'][1]['total']);
    }

    public function testInvalidCalendarDateIsRejectedBeforeReadingFacts(): void
    {
        $purchases = $this->createMock(PurchaseAnalyticsReader::class);
        $purchases->expects(self::never())->method('purchaseFacts');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('valid range');
        $this->service($this->homeStore(HomeAuthorization::MEMBER), $purchases)->report(
            $this->identity(),
            self::HOME_ID,
            'purchases',
            '2026-02-30',
            '2026-03-31',
        );
    }

    public function testPlatformRoleWithoutMembershipCannotReadAHomeReport(): void
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn(null);
        $inventory = $this->createMock(InventoryAnalyticsReader::class);
        $inventory->expects(self::never())->method('inventoryReport');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('requested resource is unavailable');
        $this->service(
            $homes,
            $this->createStub(PurchaseAnalyticsReader::class),
            null,
            $inventory,
        )->report(
            new AuthenticatedIdentity(self::USER_ID, 'session', 'device', null, ['catalog_reviewer']),
            self::HOME_ID,
            'inventory',
            null,
            null,
        );
    }

    public function testSuggestionReportPreservesUnavailableEvidenceForTheClient(): void
    {
        $intelligence = $this->createMock(ShoppingIntelligenceReader::class);
        $intelligence->method('latestSuggestions')->willReturn([
            ['homeProductId' => 'product-1', 'evidenceStatus' => 'unavailable'],
        ]);
        $intelligence->method('latestPriceComparisons')->willReturn([]);

        $report = $this->service(
            $this->homeStore(HomeAuthorization::MEMBER),
            $this->createStub(PurchaseAnalyticsReader::class),
            null,
            null,
            $intelligence,
        )->report($this->identity(), self::HOME_ID, 'suggestions', null, null);

        self::assertSame('forecast-not-ledger-fact', $report['quantitySemantics']);
        self::assertSame('unavailable', $report['data'][0]['evidenceStatus']);
    }

    private function service(
        HomeStore $homes,
        PurchaseAnalyticsReader $purchases,
        ?HomeAuditRecorder $audit = null,
        ?InventoryAnalyticsReader $inventory = null,
        ?ShoppingIntelligenceReader $intelligence = null,
    ): HomeReportService {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-04T12:00:00+00:00'));
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturn('01912345-6789-7abc-adef-0123456789ab');

        return new HomeReportService(
            new HomeAuthorization($homes),
            $inventory ?? $this->createStub(InventoryAnalyticsReader::class),
            $purchases,
            $intelligence ?? $this->createStub(ShoppingIntelligenceReader::class),
            $audit ?? $this->createStub(HomeAuditRecorder::class),
            $ids,
            $clock,
        );
    }

    private function homeStore(string $role): HomeStore
    {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn(['status' => 'active', 'role' => $role]);
        $homes->method('permissionDecision')->willReturn(null);

        return $homes;
    }

    /** @return array<string, mixed> */
    private function purchase(string $currency, string $total): array
    {
        return [
            'purchaseDate' => '2026-07-15',
            'currency' => $currency,
            'storeId' => 'store-1',
            'storeName' => 'Shop',
            'totalAmount' => $total,
        ];
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(self::USER_ID, 'session', 'device', self::HOME_ID, []);
    }
}
