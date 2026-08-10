<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogContributionService;
use Providentia\Catalog\Application\CatalogContributionStore;
use Providentia\Home\Application\HomeAuditRecorder;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;

final class CatalogContributionServiceTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const RECEIPT_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const CONTRIBUTION_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const AUDIT_ID = '01912345-6789-7abc-8def-1123456789ab';

    public function testSubmissionUsesCurrentConsentReceiptAndDropsPrivateOrQuantityFields(): void
    {
        $store = $this->createMock(CatalogContributionStore::class);
        $store->method('consent')->willReturn([
            'receiptId' => self::RECEIPT_ID,
            'revision' => 3,
            'shareProductIdentity' => true,
            'shareProductImages' => false,
            'shareStorePrices' => false,
        ]);
        $store->expects(self::once())
            ->method('createContribution')
            ->with(
                self::CONTRIBUTION_ID,
                self::HOME_ID,
                self::RECEIPT_ID,
                'product_identity',
                null,
                ['canonicalName' => 'Rolled oats', 'brand' => 'Example'],
                self::USER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(true);
        $audit = $this->createMock(HomeAuditRecorder::class);
        $audit->expects(self::once())->method('recordAudit');

        $result = $this->service($store, $audit)->submit(
            $this->identity(),
            self::HOME_ID,
            'product_identity',
            null,
            3,
            [
                'canonicalName' => 'Rolled oats',
                'brand' => 'Example',
                'quantity' => '12',
                'privateNote' => 'cupboard detail',
                'submittedBy' => self::USER_ID,
            ],
        );

        self::assertSame(['id' => self::CONTRIBUTION_ID, 'status' => 'pending', 'revision' => 1], $result);
    }

    public function testSubmissionFailsWhenTheSpecificConsentIsDisabled(): void
    {
        $store = $this->createStub(CatalogContributionStore::class);
        $store->method('consent')->willReturn([
            'receiptId' => self::RECEIPT_ID,
            'revision' => 2,
            'shareProductIdentity' => true,
            'shareProductImages' => false,
            'shareStorePrices' => false,
        ]);

        $this->expectException(Problem::class);
        $this->service($store, $this->createStub(HomeAuditRecorder::class))->submit(
            $this->identity(),
            self::HOME_ID,
            'store_price',
            null,
            2,
            [],
        );
    }

    public function testPublishedProjectionIsBoundedAndDropsEveryPrivateField(): void
    {
        $store = $this->createMock(CatalogContributionStore::class);
        $store->expects(self::once())
            ->method('published')
            ->with('store_price', 100, 0)
            ->willReturn([[
                'id' => self::CONTRIBUTION_ID,
                'contributionType' => 'store_price',
                'payload' => json_encode([
                    'productId' => '01912345-6789-7abc-cdef-0123456789ab',
                    'storeName' => 'Example Market',
                    'price' => '12.50',
                    'currency' => 'NAD',
                    'observedOn' => '2026-08-04',
                    'quantity' => '8',
                    'privateNote' => 'bottom shelf',
                    'homeId' => self::HOME_ID,
                ], JSON_THROW_ON_ERROR),
                'publishedAt' => '2026-08-04 12:00:00',
                'homeId' => self::HOME_ID,
                'submittedByUserId' => self::USER_ID,
                'consentReceiptId' => self::RECEIPT_ID,
                'sourceFingerprint' => 'private-source',
            ]]);

        $result = $this->service($store, $this->createStub(HomeAuditRecorder::class))
            ->published('store_price', 999, -10);

        self::assertSame([[
            'contributionType' => 'store_price',
            'payload' => [
                'productId' => '01912345-6789-7abc-cdef-0123456789ab',
                'storeName' => 'Example Market',
                'price' => '12.50',
                'currency' => 'NAD',
                'observedOn' => '2026-08-04',
            ],
            'publishedAt' => '2026-08-04T12:00:00+00:00',
        ]], $result);
    }

    public function testPublishedProjectionRejectsUnsupportedTypesBeforeQueryingTheStore(): void
    {
        $store = $this->createMock(CatalogContributionStore::class);
        $store->expects(self::never())->method('published');

        $this->expectException(Problem::class);
        $this->service($store, $this->createStub(HomeAuditRecorder::class))
            ->published('private_inventory', 50, 0);
    }

    public function testReviewProjectionDefensivelyDropsAttributionReturnedByAStore(): void
    {
        $store = $this->createStub(CatalogContributionStore::class);
        $store->method('reviewQueue')->willReturn([[
            'id' => self::CONTRIBUTION_ID,
            'contributionType' => 'product_identity',
            'payload' => [
                'canonicalName' => 'Rolled oats',
                'quantity' => '12',
                'privateNote' => 'cupboard detail',
            ],
            'status' => 'pending',
            'revision' => 1,
            'consentNoticeVersion' => CatalogContributionService::NOTICE_VERSION,
            'consentRevision' => 3,
            'createdAt' => '2026-08-04 12:00:00',
            'homeId' => self::HOME_ID,
            'submittedByUserId' => self::USER_ID,
            'consentReceiptId' => self::RECEIPT_ID,
        ]]);

        $identity = new AuthenticatedIdentity(
            self::USER_ID,
            'session',
            'device',
            null,
            [CatalogAuthorization::REVIEWER],
        );
        $result = $this->service($store, $this->createStub(HomeAuditRecorder::class))
            ->reviewQueue($identity, 'pending', 50, 0);

        self::assertSame([[
            'id' => self::CONTRIBUTION_ID,
            'contributionType' => 'product_identity',
            'payload' => ['canonicalName' => 'Rolled oats'],
            'status' => 'pending',
            'revision' => 1,
            'consentNoticeVersion' => CatalogContributionService::NOTICE_VERSION,
            'consentRevision' => 3,
            'createdAt' => '2026-08-04 12:00:00',
        ]], $result);
    }

    public function testModerationDecisionIsRejectedBeforeLookupWithoutAPlatformRole(): void
    {
        $store = $this->createMock(CatalogContributionStore::class);
        $store->expects(self::never())->method('contribution');
        $store->expects(self::never())->method('decide');

        $this->expectException(Problem::class);
        $this->service($store, $this->createStub(HomeAuditRecorder::class))->decide(
            $this->identity(),
            self::CONTRIBUTION_ID,
            'approved',
            'Safe public fact',
            1,
        );
    }

    public function testConsentWithdrawalIsVisibleToThePublishedProjectionImmediately(): void
    {
        $store = $this->createMock(CatalogContributionStore::class);
        $store->expects(self::once())
            ->method('saveConsent')
            ->with(
                self::CONTRIBUTION_ID,
                self::HOME_ID,
                false,
                true,
                false,
                CatalogContributionService::NOTICE_VERSION,
                4,
                self::USER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(true);
        $store->expects(self::once())->method('published')->with(null, 50, 0)->willReturn([]);
        $audit = $this->createMock(HomeAuditRecorder::class);
        $audit->expects(self::once())->method('recordAudit');
        $service = $this->service($store, $audit);

        self::assertSame([
            'homeId' => self::HOME_ID,
            'shareProductIdentity' => false,
            'shareProductImages' => true,
            'shareStorePrices' => false,
            'noticeVersion' => CatalogContributionService::NOTICE_VERSION,
            'revision' => 5,
        ], $service->configureConsent(
            $this->identity(),
            self::HOME_ID,
            false,
            true,
            false,
            CatalogContributionService::NOTICE_VERSION,
            4,
        ));
        self::assertSame([], $service->published(null, 50, 0));
    }

    private function service(
        CatalogContributionStore $store,
        HomeAuditRecorder $audit,
    ): CatalogContributionService {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn([
            'home_id' => self::HOME_ID,
            'user_id' => self::USER_ID,
            'status' => 'active',
            'role' => HomeAuthorization::OWNER,
            'revision' => 1,
        ]);
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturnOnConsecutiveCalls(self::CONTRIBUTION_ID, self::AUDIT_ID);

        return new CatalogContributionService(
            $store,
            new HomeAuthorization($homes),
            new CatalogAuthorization(),
            $audit,
            $ids,
            new HomeFixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00')),
            new RecordingTransactionManager(),
        );
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER_ID,
            '01912345-6789-7abc-9def-1123456789ab',
            '01912345-6789-7abc-adef-1123456789ab',
            self::HOME_ID,
            [],
        );
    }
}
