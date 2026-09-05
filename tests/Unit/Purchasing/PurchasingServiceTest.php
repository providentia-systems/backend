<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Purchasing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryMovementGateway;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Purchasing\Application\PurchasingStore;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\UuidGenerator;
use ProvidentiaTest\Unit\Home\HomeFixedClock;
use ProvidentiaTest\Unit\Home\RecordingTransactionManager;

final class PurchasingServiceTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const USER_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const RECEIPT_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const LINE_ID = '01912345-6789-7abc-bdef-0123456789ab';
    private const PRODUCT_ID = '01912345-6789-7abc-cdef-0123456789ab';
    private const STORE_ID = '01912345-6789-7abc-ddef-0123456789ab';
    private const PACK_ID = '01912345-6789-7abc-edef-0123456789ab';
    private const PRICE_ID = '01912345-6789-7abc-8def-1123456789ab';
    public function testApprovingLinePublishesUpdatedLineAndPublicReceiptProjection(): void
    {
        $draftReceipt = [
            'id' => self::RECEIPT_ID,
            'homeId' => self::HOME_ID,
            'storeId' => self::STORE_ID,
            'storeName' => 'Internal store label',
            'purchaseDate' => '2026-08-03',
            'currency' => 'NAD',
            'totalAmount' => '37.50',
            'status' => 'draft',
            'source' => 'manual',
            'sourceReference' => null,
            'notes' => 'Household groceries',
            'revision' => 6,
            'createdByUserId' => self::USER_ID,
            'createdAt' => '2026-08-03T10:00:00+00:00',
            'updatedAt' => '2026-08-03T10:00:00+00:00',
        ];
        $updatedReceipt = [
            ...$draftReceipt,
            'revision' => 7,
            'updatedAt' => '2026-08-04T12:00:00+00:00',
        ];
        $updatedLine = [
            'id' => self::LINE_ID,
            'lineNumber' => 1,
            'rawDescription' => '3 pantry items',
            'quantity' => '3',
            'originalPackText' => null,
            'unitPrice' => '12.50',
            'lineTotal' => '37.50',
            'homeProductId' => self::PRODUCT_ID,
            'productId' => null,
            'packId' => self::PACK_ID,
            'productName' => 'Private pantry item',
            'approvalStatus' => 'approved',
            'revision' => 2,
            'createdAt' => '2026-08-03T10:00:00+00:00',
            'updatedAt' => '2026-08-04T12:00:00+00:00',
        ];
        $purchases = $this->createMock(PurchasingStore::class);
        $purchases->expects(self::exactly(2))
            ->method('receipt')
            ->with(
                self::HOME_ID,
                self::RECEIPT_ID,
            )
            ->willReturnOnConsecutiveCalls(
                $draftReceipt,
                $updatedReceipt,
            );
        $purchases->expects(self::once())
            ->method('approveReceiptLine')
            ->with(
                self::PRICE_ID,
                self::HOME_ID,
                self::RECEIPT_ID,
                self::LINE_ID,
                self::PRODUCT_ID,
                1,
                self::USER_ID,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                true,
            );
        $purchases->expects(self::once())
            ->method('receiptLine')
            ->with(
                self::HOME_ID,
                self::RECEIPT_ID,
                self::LINE_ID,
            )
            ->willReturn(
                $updatedLine,
            );
        $published = [];
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::exactly(2))
            ->method('put')
            ->willReturnCallback(
                static function (
                    string $homeId,
                    string $actorUserId,
                    string $entityType,
                    string $entityId,
                    int $revision,
                    array $representation,
                    DateTimeImmutable $at,
                ) use (&$published): int {
                    $published[] = [
                    'homeId' => $homeId,
                    'actorUserId' => $actorUserId,
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'revision' => $revision,
                    'representation' => $representation,
                    'at' => $at->format(DATE_ATOM),
                    ];
                    return count($published);
                },
            );
        $this->service(
            $purchases,
            $this->createStub(InventoryMovementGateway::class),
            $changes,
        )
            ->approveLine(
                $this->identity(),
                self::HOME_ID,
                self::RECEIPT_ID,
                self::LINE_ID,
                self::PRODUCT_ID,
                1,
            );
        self::assertSame(
            [
                [
                    'homeId' => self::HOME_ID,
                    'actorUserId' => self::USER_ID,
                    'entityType' => 'purchasing-receipt-line',
                    'entityId' => self::LINE_ID,
                    'revision' => 2,
                    'representation' => array_diff_key($updatedLine, array_flip(['id', 'revision'])),
                    'at' => '2026-08-04T12:00:00+00:00',
                ],
                [
                    'homeId' => self::HOME_ID,
                    'actorUserId' => self::USER_ID,
                    'entityType' => 'purchasing-receipt',
                    'entityId' => self::RECEIPT_ID,
                    'revision' => 7,
                    'representation' => [
                        'storeId' => self::STORE_ID,
                        'purchaseDate' => '2026-08-03',
                        'currency' => 'NAD',
                        'totalAmount' => '37.50',
                        'status' => 'draft',
                        'source' => 'manual',
                        'sourceReference' => null,
                        'notes' => 'Household groceries',
                    ],
                    'at' => '2026-08-04T12:00:00+00:00',
                ],
            ],
            $published,
        );
    }

    public function testLeavingLineUnresolvedIsRevisionedAndPublishesAuthoritativeProjection(): void
    {
        $draftReceipt = [
            'id' => self::RECEIPT_ID,
            'storeId' => self::STORE_ID,
            'purchaseDate' => '2026-08-03',
            'currency' => 'NAD',
            'totalAmount' => '37.50',
            'status' => 'draft',
            'source' => 'manual',
            'sourceReference' => null,
            'notes' => 'Review retained',
            'revision' => 6,
        ];
        $approvedLine = [
            'id' => self::LINE_ID,
            'receiptId' => self::RECEIPT_ID,
            'rawDescription' => 'Unknown pantry item',
            'quantity' => '1',
            'originalPackText' => '500 g',
            'unitPrice' => '12.50',
            'lineTotal' => '12.50',
            'homeProductId' => self::PRODUCT_ID,
            'approvalStatus' => 'approved',
            'revision' => 2,
        ];
        $unresolvedLine = [
            ...$approvedLine,
            'homeProductId' => null,
            'approvalStatus' => 'unresolved',
            'revision' => 3,
        ];
        $updatedReceipt = [...$draftReceipt, 'revision' => 7];
        $purchases = $this->createMock(PurchasingStore::class);
        $purchases->expects(self::exactly(2))
            ->method('receipt')
            ->with(
                self::HOME_ID,
                self::RECEIPT_ID,
            )
            ->willReturnOnConsecutiveCalls(
                $draftReceipt,
                $updatedReceipt,
            );
        $purchases->expects(self::exactly(2))
            ->method('receiptLine')
            ->with(
                self::HOME_ID,
                self::RECEIPT_ID,
                self::LINE_ID,
            )
            ->willReturnOnConsecutiveCalls(
                $approvedLine,
                $unresolvedLine,
            );
        $purchases->expects(self::once())
            ->method('markReceiptLineUnresolved')
            ->with(
                self::HOME_ID,
                self::RECEIPT_ID,
                self::LINE_ID,
                2,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                true,
            );
        $published = [];
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::exactly(2))
            ->method('put')
            ->willReturnCallback(
                static function (
                    string $homeId,
                    string $actorUserId,
                    string $entityType,
                    string $entityId,
                    int $revision,
                    array $representation,
                ) use (&$published): int {
                    $published[] = compact(
                        'homeId',
                        'actorUserId',
                        'entityType',
                        'entityId',
                        'revision',
                        'representation',
                    );
                    return count($published);
                },
            );
        $result = $this->service(
            $purchases,
            $this->createStub(InventoryMovementGateway::class),
            $changes,
        )
            ->unresolveLine(
                $this->identity(),
                self::HOME_ID,
                self::RECEIPT_ID,
                self::LINE_ID,
                2,
            );
        self::assertSame(
            [
                'id' => self::LINE_ID,
                'revision' => 3,
                'approvalStatus' => 'unresolved',
            ],
            $result,
        );
        self::assertSame(
            'purchasing-receipt-line',
            $published[0]['entityType'],
        );
        self::assertSame(3, $published[0]['revision']);
        self::assertSame(
            'Unknown pantry item',
            $published[0]['representation']['rawDescription'],
        );
        self::assertNull($published[0]['representation']['homeProductId']);
        self::assertSame(
            'unresolved',
            $published[0]['representation']['approvalStatus'],
        );
        self::assertSame(
            'purchasing-receipt',
            $published[1]['entityType'],
        );
        self::assertSame(7, $published[1]['revision']);
    }

    public function testUnresolvedDecisionReplayIsIdempotentEvenAfterReceiptCommit(): void
    {
        $purchases = $this->createMock(PurchasingStore::class);
        $purchases->method('receipt')
            ->willReturn(
                [
                'id' => self::RECEIPT_ID,
                'status' => 'committed',
                'revision' => 7,
                ],
            );
        $purchases->method('receiptLine')
            ->willReturn(
                [
                'id' => self::LINE_ID,
                'approvalStatus' => 'unresolved',
                'revision' => 3,
                ],
            );
        $purchases->expects(self::never())
            ->method('markReceiptLineUnresolved');
        $changes = $this->createMock(ChangeFeedWriter::class);
        $changes->expects(self::never())
            ->method('put');
        self::assertSame(
            [
                'id' => self::LINE_ID,
                'revision' => 3,
                'approvalStatus' => 'unresolved',
            ],
            $this->service(
                $purchases,
                $this->createStub(InventoryMovementGateway::class),
                $changes,
            )
                ->unresolveLine(
                    $this->identity(),
                    self::HOME_ID,
                    self::RECEIPT_ID,
                    self::LINE_ID,
                    2,
                ),
        );
    }

    public function testUnresolvedDecisionConcealsViewerAndForeignHomeWithoutStoreAccess(): void
    {
        foreach (
            [
            [
                'status' => 'active',
                'role' => HomeAuthorization::VIEWER,
            ],
            null,
            ] as $membership
        ) {
            $purchases = $this->createMock(PurchasingStore::class);
            $purchases->expects(self::never())
                ->method('receipt');
            $purchases->expects(self::never())
                ->method('receiptLine');
            $purchases->expects(self::never())
                ->method('markReceiptLineUnresolved');
            $homes = $this->createStub(HomeStore::class);
            $homes->method('membership')
                ->willReturn($membership);
            try {
                $this->service(
                    $purchases,
                    $this->createStub(InventoryMovementGateway::class),
                    null,
                    $homes,
                )
                    ->unresolveLine(
                        $this->identity(),
                        self::HOME_ID,
                        self::RECEIPT_ID,
                        self::LINE_ID,
                        2,
                    );
                self::fail(
                    'A read-only or foreign home changed a private receipt line.',
                );
            } catch (Problem $problem) {
                self::assertSame(404, $problem->status);
                self::assertSame(
                    'The requested resource is unavailable.',
                    $problem->getMessage(),
                );
            }
        }
    }

    public function testCommittedReceiptIsAnIdempotentReplayWithoutDuplicateMovements(): void
    {
        $purchases = $this->createMock(PurchasingStore::class);
        $purchases->expects(self::once())
            ->method('receipt')
            ->with(
                self::HOME_ID,
                self::RECEIPT_ID,
            )
            ->willReturn(
                [
                'id' => self::RECEIPT_ID,
                'status' => 'committed',
                'revision' => 8,
                ],
            );
        $purchases->expects(self::never())
            ->method('receiptLines');
        $purchases->expects(self::never())
            ->method('markReceiptCommitted');
        $inventory = $this->createMock(InventoryMovementGateway::class);
        $inventory->expects(self::never())
            ->method('recordApprovedInbound');
        self::assertSame(
            [
                'receiptId' => self::RECEIPT_ID,
                'movements' => 0,
            ],
            $this->service($purchases, $inventory)
                ->commit(
                    $this->identity(),
                    self::HOME_ID,
                    self::RECEIPT_ID,
                    7,
                ),
        );
    }

    public function testReceiptRevisionConflictHappensBeforeLineOrInventoryWrites(): void
    {
        $purchases = $this->createMock(PurchasingStore::class);
        $purchases->method('receipt')
            ->willReturn(
                [
                'id' => self::RECEIPT_ID,
                'status' => 'draft',
                'revision' => 5,
                ],
            );
        $purchases->expects(self::never())
            ->method('receiptLines');
        $purchases->expects(self::never())
            ->method('markReceiptCommitted');
        $inventory = $this->createMock(InventoryMovementGateway::class);
        $inventory->expects(self::never())
            ->method('recordApprovedInbound');
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('changed on another device');
        $this->service($purchases, $inventory)
            ->commit(
                $this->identity(),
                self::HOME_ID,
                self::RECEIPT_ID,
                4,
            );
    }

    public function testCommitCreatesOneInboundMovementAndPriceFactPerApprovedLine(): void
    {
        $receipt = [
            'id' => self::RECEIPT_ID,
            'status' => 'draft',
            'revision' => 4,
            'storeId' => self::STORE_ID,
            'purchaseDate' => '2026-08-03',
            'currency' => 'NAD',
            'totalAmount' => '37.50',
            'source' => 'manual',
            'sourceReference' => null,
            'notes' => '',
        ];
        $line = [
            'id' => self::LINE_ID,
            'approvalStatus' => 'approved',
            'homeProductId' => self::PRODUCT_ID,
            'quantity' => '3',
            'packId' => self::PACK_ID,
            'unitPrice' => '12.50',
            'lineTotal' => '37.50',
        ];
        $purchases = $this->createMock(PurchasingStore::class);
        $purchases->method('receipt')
            ->with(self::HOME_ID, self::RECEIPT_ID)
            ->willReturn(
                $receipt,
            );
        $purchases->method('receiptLines')
            ->with(self::HOME_ID, self::RECEIPT_ID)
            ->willReturn(
                [
                $line,
                [
                    'id' => '01912345-6789-7abc-bdef-1123456789ab',
                    'approvalStatus' => 'unresolved',
                    'homeProductId' => null,
                    'rawDescription' => 'Unmatched handwritten item',
                    'quantity' => '1',
                    'unitPrice' => null,
                    'lineTotal' => '5.00',
                ],
                ],
            );
        $purchases->expects(self::once())
            ->method('recordPriceObservation')
            ->with(
                self::PRICE_ID,
                self::HOME_ID,
                self::LINE_ID,
                self::PACK_ID,
                self::STORE_ID,
                'NAD',
                '3',
                '12.50',
                '37.50',
                self::callback(
                    static fn(DateTimeImmutable $at): bool => $at->format('Y-m-d') === '2026-08-03',
                ),
                self::isInstanceOf(DateTimeImmutable::class),
            );
        $purchases->expects(self::once())
            ->method('markReceiptCommitted')
            ->with(
                self::HOME_ID,
                self::RECEIPT_ID,
                4,
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(
                true,
            );
        $inventory = $this->createMock(InventoryMovementGateway::class);
        $inventory->expects(self::once())
            ->method('recordApprovedInbound')
            ->with(
                self::USER_ID,
                self::HOME_ID,
                self::PRODUCT_ID,
                '3',
                'receipt-line',
                self::LINE_ID,
                'Approved receipt line',
                self::callback(
                    static fn(DateTimeImmutable $at): bool => $at->format('Y-m-d') === '2026-08-03',
                ),
            )
            ->willReturn(
                ['id' => 'movement-1'],
            );
        self::assertSame(
            [
                'receiptId' => self::RECEIPT_ID,
                'movements' => 1,
            ],
            $this->service($purchases, $inventory)
                ->commit(
                    $this->identity(),
                    self::HOME_ID,
                    self::RECEIPT_ID,
                    4,
                ),
        );
    }

    public function testCommitRejectsUnreviewedLinesWithoutInventoryMutation(): void
    {
        $purchases = $this->createMock(PurchasingStore::class);
        $purchases->method('receipt')
            ->willReturn(
                [
                'id' => self::RECEIPT_ID,
                'status' => 'draft',
                'revision' => 4,
                'purchaseDate' => '2026-08-03',
                'currency' => 'NAD',
                ],
            );
        $purchases->method('receiptLines')
            ->willReturn(
                [
                [
                    'id' => self::LINE_ID,
                    'approvalStatus' => 'unreviewed',
                    'homeProductId' => null,
                ],
                ],
            );
        $purchases->expects(self::never())
            ->method('markReceiptCommitted');
        $inventory = $this->createMock(InventoryMovementGateway::class);
        $inventory->expects(self::never())
            ->method('recordApprovedInbound');
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('explicitly approved or left unresolved');
        $this->service($purchases, $inventory)
            ->commit(
                $this->identity(),
                self::HOME_ID,
                self::RECEIPT_ID,
                4,
            );
    }

    private function service(
        PurchasingStore $purchases,
        InventoryMovementGateway $inventory,
        ?ChangeFeedWriter $changes = null,
        ?HomeStore $homes = null,
    ): PurchasingService {
        if ($homes === null) {
            $homes = $this->createStub(HomeStore::class);
            $homes->method('membership')
                ->willReturn(
                    [
                    'status' => 'active',
                    'role' => HomeAuthorization::OWNER,
                    ],
                );
        }
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')
            ->willReturn(self::PRICE_ID);
        return new PurchasingService(
            $purchases,
            $inventory,
            new HomeAuthorization(
                $homes,
                \ProvidentiaTest\Support\AccessFixture::create(),
            ),
            $ids,
            new HomeFixedClock(
                new DateTimeImmutable('2026-08-04T12:00:00+00:00'),
            ),
            new RecordingTransactionManager(),
            $changes,
        );
    }

    private function identity(): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            self::USER_ID,
            'session',
            'device',
            self::HOME_ID,
            [],
            \ProvidentiaTest\Support\AccessFixture::administratorPermissions([]),
        );
    }
}
