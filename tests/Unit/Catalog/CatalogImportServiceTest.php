<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Application\CatalogHomeAccess;
use Providentia\Catalog\Application\CatalogImportService;
use Providentia\Catalog\Application\CatalogImportStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;

final class CatalogImportServiceTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const OTHER_HOME_ID = '02912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const PRODUCT_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const HOME_PRODUCT_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const BATCH_ID = '01912345-6789-7abc-cdef-0123456789ab';

    public function testStageResolvesAndPersistsWithoutMutatingTheHomeCatalog(): void
    {
        $store = $this->createMock(CatalogImportStore::class);
        $store->method('findByIdempotency')->willReturn(null);
        $store->expects(self::once())->method('resolve')->with(
            self::HOME_ID,
            null,
            null,
            '6001000000001',
            'rolled oats',
            'example',
            'rolled oats',
        )->willReturn([
            'resolution' => 'global_match',
            'productId' => self::PRODUCT_ID,
            'packId' => null,
        ]);
        $store->expects(self::once())->method('createBatch')->with(
            self::BATCH_ID,
            self::HOME_ID,
            self::USER_ID,
            self::isString(),
            self::isString(),
            self::callback(static function (array $rows): bool {
                return count($rows) === 1
                    && $rows[0]['resolution'] === 'link_catalog'
                    && $rows[0]['targetHomeProductId'] === self::HOME_PRODUCT_ID
                    && $rows[0]['productId'] === self::PRODUCT_ID
                    && $rows[0]['errorCode'] === null;
            }),
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn(true);
        $store->method('batch')->willReturn($this->batch());

        $result = $this->service($store, [self::HOME_PRODUCT_ID, self::BATCH_ID])->stage(
            $this->identity(),
            self::HOME_ID,
            'catalog-upload-1',
            [[
                'recordType' => 'catalog_product_reference',
                'barcode' => '6001000000001',
                'name' => '  Rolled   Oats ',
                'brand' => 'Example',
            ]],
        );

        self::assertFalse($result['replayed']);
        self::assertSame('staged', $result['status']);
    }

    public function testStockAndPriceFieldsBecomeRowErrorsAndAreNeverResolved(): void
    {
        $store = $this->createMock(CatalogImportStore::class);
        $store->method('findByIdempotency')->willReturn(null);
        $store->expects(self::never())->method('resolve');
        $store->expects(self::once())->method('createBatch')->with(
            self::BATCH_ID,
            self::HOME_ID,
            self::USER_ID,
            self::isString(),
            self::isString(),
            self::callback(static function (array $rows): bool {
                return $rows[0]['resolution'] === 'error'
                    && $rows[0]['errorCode'] === 'unsupported_mutation';
            }),
            self::isInstanceOf(DateTimeImmutable::class),
        )->willReturn(true);
        $store->method('batch')->willReturn($this->batch(errorCount: 1, validCount: 0));

        $result = $this->service($store, [self::BATCH_ID])->stage(
            $this->identity(),
            self::HOME_ID,
            'catalog-upload-2',
            [[
                'recordType' => 'home_product',
                'name' => 'Milk',
                'quantity' => '3',
                'price' => '10.00',
            ]],
        );

        self::assertSame(1, $result['errorCount']);
    }

    public function testAnIdempotencyKeyCannotBeReusedForDifferentContent(): void
    {
        $store = $this->createStub(CatalogImportStore::class);
        $store->method('findByIdempotency')->willReturn([
            'id' => self::BATCH_ID,
            'contentHash' => str_repeat('0', 64),
        ]);

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('different rows');
        $this->service($store)->stage(
            $this->identity(),
            self::HOME_ID,
            'catalog-upload-3',
            [['recordType' => 'home_product', 'name' => 'Rice']],
        );
    }

    public function testConfirmationRequiresExactRevisionAndExplicitPhrase(): void
    {
        $store = $this->createMock(CatalogImportStore::class);
        $store->expects(self::never())->method('confirmBatch');

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('apply_catalog_records');
        $this->service($store)->confirm(
            $this->identity(),
            self::HOME_ID,
            self::BATCH_ID,
            1,
            'yes',
        );
    }

    public function testConfirmationPublishesEveryCreatedHomeProductToTheChangeFeed(): void
    {
        $store = $this->createMock(CatalogImportStore::class);
        $store->method('batch')->willReturnOnConsecutiveCalls(
            $this->batch(),
            [...$this->batch(), 'status' => 'confirmed', 'revision' => 2, 'importedCount' => 1],
        );
        $store->expects(self::once())->method('confirmBatch')->willReturn([
            'confirmed' => true,
            'imported' => [[
                'id' => self::HOME_PRODUCT_ID,
                'productId' => self::PRODUCT_ID,
                'packId' => null,
                'privateName' => null,
                'originalPackText' => null,
            ]],
        ]);
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::once())->method('put')->with(
            self::HOME_ID,
            self::USER_ID,
            'inventory-home-product',
            self::HOME_PRODUCT_ID,
            1,
            [
                'productId' => self::PRODUCT_ID,
                'packId' => null,
                'privateName' => null,
                'originalPackText' => null,
                'status' => 'active',
            ],
            self::isInstanceOf(DateTimeImmutable::class),
        );

        $result = $this->service($store, changes: $changes)->confirm(
            $this->identity(),
            self::HOME_ID,
            self::BATCH_ID,
            1,
            CatalogImportService::CONFIRMATION,
        );

        self::assertSame('confirmed', $result['status']);
        self::assertFalse($result['replayed']);
    }

    public function testCrossHomeBatchLookupIsHiddenBeforeStoreAccess(): void
    {
        $store = $this->createMock(CatalogImportStore::class);
        $store->expects(self::never())->method('batch');

        $this->expectException(Problem::class);
        $problem = null;
        try {
            $this->service($store)->get($this->identity(), self::OTHER_HOME_ID, self::BATCH_ID);
        } catch (Problem $caught) {
            $problem = $caught;
            throw $caught;
        } finally {
            if ($problem !== null) {
                self::assertSame(404, $problem->status);
            }
        }
    }

    /** @param list<string> $ids */
    private function service(
        CatalogImportStore $store,
        array $ids = [],
        ?ChangeFeedWriter $changes = null,
    ): CatalogImportService {
        $homes = $this->createStub(CatalogHomeAccess::class);
        $homes->method('requireImport')->willReturnCallback(
            static function (AuthenticatedIdentity $_identity, string $homeId): void {
                if ($homeId !== self::HOME_ID) {
                    throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
                }
            },
        );
        $uuid = $this->createStub(UuidGenerator::class);
        if ($ids !== []) {
            $uuid->method('generate')->willReturnOnConsecutiveCalls(...$ids);
        }

        return new CatalogImportService(
            $store,
            $homes,
            $uuid,
            new HomeFixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00')),
            new RecordingTransactionManager(),
            $changes ?? $this->createStub(ChangeFeedWriter::class),
        );
    }

    /** @return array<string, mixed> */
    private function batch(int $errorCount = 0, int $validCount = 1): array
    {
        return [
            'id' => self::BATCH_ID,
            'homeId' => self::HOME_ID,
            'contentHash' => str_repeat('a', 64),
            'status' => 'staged',
            'rowCount' => 1,
            'validCount' => $validCount,
            'errorCount' => $errorCount,
            'importedCount' => 0,
            'skippedCount' => 0,
            'revision' => 1,
            'rows' => [],
        ];
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
