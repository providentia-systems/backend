<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Application\CatalogAuditRecorder;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogContributionService;
use Providentia\Catalog\Application\CatalogContributionImageStore;
use Providentia\Catalog\Application\CatalogContributionSourceReader;
use Providentia\Catalog\Application\CatalogContributionStore;
use Providentia\Catalog\Application\CatalogHomeAccess;
use Providentia\Catalog\Application\PublishedPackReader;
use Providentia\Catalog\Http\CatalogContributionHandler;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
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
    private const SOURCE_ID = '01912345-6789-7abc-8def-2123456789ab';

    public function testSubmissionHandlerRejectsFieldsOutsideTheClosedContract(): void
    {
        $store = $this->createMock(CatalogContributionStore::class);
        $store->expects(self::never())->method('consent');
        $handler = new CatalogContributionHandler(
            $this->service($store, $this->createStub(CatalogAuditRecorder::class)),
            'submit',
        );
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://app.example.test/api/v1/homes/' . self::HOME_ID . '/catalog-contributions'),
            'POST',
            'php://memory',
        ))
            ->withAttribute('homeId', self::HOME_ID)
            ->withAttribute(BearerAuthenticationMiddleware::ATTRIBUTE, $this->identity())
            ->withParsedBody([
                'submissionId' => self::CONTRIBUTION_ID,
                'type' => 'product_identity',
                'sourceEntityId' => self::SOURCE_ID,
                'expectedConsentRevision' => 3,
                'payload' => ['canonicalName' => 'Oats', 'brand' => 'Example'],
                'unexpected' => 'ignored',
            ]);

        try {
            $handler->handle($request);
            self::fail('A contribution request outside the closed JSON contract was accepted.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
        }
    }

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
                self::SOURCE_ID,
                ['canonicalName' => 'Rolled oats', 'brand' => 'Example'],
                self::USER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn([
                'outcome' => 'created',
                'record' => [
                    'id' => self::CONTRIBUTION_ID,
                    'contributionType' => 'product_identity',
                    'payload' => ['canonicalName' => 'Rolled oats', 'brand' => 'Example'],
                    'status' => 'pending',
                    'revision' => 1,
                    'createdAt' => '2026-08-04T12:00:00+00:00',
                ],
            ]);
        $audit = $this->createMock(CatalogAuditRecorder::class);
        $audit->expects(self::once())->method('recordAudit');

        $result = $this->service($store, $audit)->submit(
            $this->identity(),
            self::HOME_ID,
            self::CONTRIBUTION_ID,
            'product_identity',
            self::SOURCE_ID,
            3,
            [
                'canonicalName' => 'Rolled oats',
                'brand' => 'Example',
                'quantity' => '12',
                'privateNote' => 'cupboard detail',
                'submittedBy' => self::USER_ID,
            ],
        );

        self::assertTrue($result->created);
        self::assertSame(self::CONTRIBUTION_ID, $result->contribution['id']);
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
        $this->service($store, $this->createStub(CatalogAuditRecorder::class))->submit(
            $this->identity(),
            self::HOME_ID,
            self::CONTRIBUTION_ID,
            'store_price',
            self::SOURCE_ID,
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
                    'productId' => '01912345-6789-7abc-8def-3123456789ab',
                    'packId' => '01912345-6789-7abc-9def-3123456789ab',
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

        $result = $this->service($store, $this->createStub(CatalogAuditRecorder::class))
            ->published('store_price', 999, -10);

        self::assertSame([[
            'contributionType' => 'store_price',
            'payload' => [
                'productId' => '01912345-6789-7abc-8def-3123456789ab',
                'packId' => '01912345-6789-7abc-9def-3123456789ab',
                'storeName' => 'Example Market',
                'price' => '12.50',
                'currency' => 'NAD',
                'observedOn' => '2026-08-04',
            ],
            'publishedAt' => '2026-08-04T12:00:00+00:00',
        ]], $result);
    }

    public function testStorePriceMustMatchTheSelectedHomeItemsPublishedPack(): void
    {
        $store = $this->createMock(CatalogContributionStore::class);
        $store->method('consent')->willReturn([
            'receiptId' => self::RECEIPT_ID,
            'revision' => 3,
            'shareProductIdentity' => false,
            'shareProductImages' => false,
            'shareStorePrices' => true,
        ]);
        $store->expects(self::never())->method('createContribution');
        $source = $this->createStub(CatalogContributionSourceReader::class);
        $source->method('activeHomeProduct')->willReturn([
            'productId' => '01912345-6789-7abc-8def-3123456789ab',
            'packId' => '01912345-6789-7abc-adef-3123456789ab',
        ]);

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('does not use that published pack');
        $this->service(
            $store,
            $this->createStub(CatalogAuditRecorder::class),
            sources: $source,
        )->submit(
            $this->identity(),
            self::HOME_ID,
            self::CONTRIBUTION_ID,
            'store_price',
            self::SOURCE_ID,
            3,
            [
                'productId' => '01912345-6789-7abc-8def-3123456789ab',
                'packId' => '01912345-6789-7abc-9def-3123456789ab',
                'storeName' => 'Market',
                'price' => '12.50',
                'currency' => 'NAD',
                'observedOn' => '2026-08-04',
            ],
        );
    }

    public function testStorePriceCannotClaimAFutureObservation(): void
    {
        $store = $this->createStub(CatalogContributionStore::class);
        $store->method('consent')->willReturn([
            'receiptId' => self::RECEIPT_ID,
            'revision' => 3,
            'shareProductIdentity' => false,
            'shareProductImages' => false,
            'shareStorePrices' => true,
        ]);

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('cannot be in the future');
        $this->service($store, $this->createStub(CatalogAuditRecorder::class))->submit(
            $this->identity(),
            self::HOME_ID,
            self::CONTRIBUTION_ID,
            'store_price',
            self::SOURCE_ID,
            3,
            [
                'productId' => '01912345-6789-7abc-8def-3123456789ab',
                'packId' => '01912345-6789-7abc-9def-3123456789ab',
                'storeName' => 'Market',
                'price' => '12.50',
                'currency' => 'NAD',
                'observedOn' => '2026-08-05',
            ],
        );
    }

    public function testPublishedProjectionRejectsUnsupportedTypesBeforeQueryingTheStore(): void
    {
        $store = $this->createMock(CatalogContributionStore::class);
        $store->expects(self::never())->method('published');

        $this->expectException(Problem::class);
        $this->service($store, $this->createStub(CatalogAuditRecorder::class))
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
            'status' => 'approved',
            'revision' => 2,
            'consentNoticeVersion' => CatalogContributionService::NOTICE_VERSION,
            'consentRevision' => 3,
            'createdAt' => '2026-08-04T12:00:00+00:00',
            'linkedContributionRevision' => 2,
            'proposalId' => '01912345-6789-7abc-8def-4123456789ab',
            'proposalStatus' => 'pending',
            'publishedCategoryId' => '01912345-6789-7abc-9def-4123456789ab',
            'publishedCategoryName' => 'Breakfast',
            'linkedAt' => '2026-08-04 12:30:00',
            'linkedByUserId' => self::USER_ID,
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
        $result = $this->service($store, $this->createStub(CatalogAuditRecorder::class))
            ->reviewQueue($identity, 'approved', 50, 0);

        self::assertSame([[
            'id' => self::CONTRIBUTION_ID,
            'contributionType' => 'product_identity',
            'payload' => ['canonicalName' => 'Rolled oats'],
            'status' => 'approved',
            'revision' => 2,
            'consentNoticeVersion' => CatalogContributionService::NOTICE_VERSION,
            'consentRevision' => 3,
            'createdAt' => '2026-08-04T12:00:00+00:00',
            'proposalLink' => [
                'contributionId' => self::CONTRIBUTION_ID,
                'contributionRevision' => 2,
                'proposalId' => '01912345-6789-7abc-8def-4123456789ab',
                'proposalStatus' => 'pending',
                'publishedCategoryId' => '01912345-6789-7abc-9def-4123456789ab',
                'publishedCategoryName' => 'Breakfast',
                'linkedAt' => '2026-08-04T12:30:00+00:00',
            ],
        ]], $result);
    }

    public function testModerationDecisionIsRejectedBeforeLookupWithoutAPlatformRole(): void
    {
        $store = $this->createMock(CatalogContributionStore::class);
        $store->expects(self::never())->method('contribution');
        $store->expects(self::never())->method('decide');

        $this->expectException(Problem::class);
        $this->service($store, $this->createStub(CatalogAuditRecorder::class))->decide(
            $this->identity(),
            self::CONTRIBUTION_ID,
            'approved',
            'Safe public fact',
            1,
        );
    }

    public function testImageReviewProjectionNeverExposesTheHouseholdSourceDigest(): void
    {
        $store = $this->createStub(CatalogContributionStore::class);
        $store->method('reviewQueue')->willReturn([[
            'id' => self::CONTRIBUTION_ID,
            'contributionType' => 'product_image',
            'payload' => [
                'sourceDigest' => hash('sha256', 'private-upload'),
                'assetDigest' => hash('sha256', 'sanitized-webp'),
                'mediaType' => 'image/webp',
                'altText' => 'Front of package',
                'provenance' => 'homeowner_original',
                'rightsDeclarationVersion' => 'homeowner_original_public_catalog_v1',
                'reuseNoticeVersion' => 'catalog-image-public-reuse-v1',
            ],
            'status' => 'pending',
            'revision' => 1,
            'consentNoticeVersion' => CatalogContributionService::NOTICE_VERSION,
            'consentRevision' => 3,
            'createdAt' => '2026-08-04T12:00:00+00:00',
        ]]);
        $identity = new AuthenticatedIdentity(
            self::USER_ID,
            'session',
            'device',
            null,
            [CatalogAuthorization::REVIEWER],
        );

        $rows = $this->service($store, $this->createStub(CatalogAuditRecorder::class))
            ->reviewQueue($identity, 'pending', 50, 0);

        self::assertArrayNotHasKey('sourceDigest', $rows[0]['payload']);
        self::assertSame(hash('sha256', 'sanitized-webp'), $rows[0]['payload']['assetDigest']);
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
        $audit = $this->createMock(CatalogAuditRecorder::class);
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
        CatalogAuditRecorder $audit,
        ?CatalogContributionSourceReader $sources = null,
        ?PublishedPackReader $packs = null,
    ): CatalogContributionService {
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturnOnConsecutiveCalls(self::CONTRIBUTION_ID, self::AUDIT_ID);

        return new CatalogContributionService(
            $store,
            $this->createStub(CatalogContributionImageStore::class),
            $sources ?? $this->sourceReader(),
            $packs ?? $this->packReader(),
            $this->createStub(CatalogHomeAccess::class),
            new CatalogAuthorization(),
            $audit,
            $ids,
            new HomeFixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00')),
            new RecordingTransactionManager(),
        );
    }

    private function sourceReader(): CatalogContributionSourceReader
    {
        $reader = $this->createStub(CatalogContributionSourceReader::class);
        $reader->method('activeHomeProduct')->willReturn([
            'productId' => '01912345-6789-7abc-8def-3123456789ab',
            'packId' => '01912345-6789-7abc-9def-3123456789ab',
        ]);

        return $reader;
    }

    private function packReader(): PublishedPackReader
    {
        $reader = $this->createStub(PublishedPackReader::class);
        $reader->method('lockPublishedPack')->willReturn(true);

        return $reader;
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
