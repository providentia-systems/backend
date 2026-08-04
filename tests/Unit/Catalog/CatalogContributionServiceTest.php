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
