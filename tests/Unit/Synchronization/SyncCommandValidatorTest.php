<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\SyncCommandValidator;

final class SyncCommandValidatorTest extends TestCase
{
    public function testReceiptDateCorrectionIsClosedRevisionedAndPreservesCalendarText(): void
    {
        $validator = new SyncCommandValidator(65536);
        $command = $validator->validate($this->command(
            'purchasing.receipt.correct-date',
            8,
            ['purchaseDate' => '2026-10-15', 'reason' => 'Verified original receipt'],
        ));
        self::assertSame(8, $command->baseRevision);
        self::assertSame('2026-10-15', $command->payload['purchaseDate']);
        $this->expectException(Problem::class);
        $validator->validate($this->command(
            'purchasing.receipt.correct-date',
            0,
            ['purchaseDate' => '2026-10-15', 'reason' => 'Missing original revision'],
        ));
    }

    public function testClosedTypedCommandIsAccepted(): void
    {
        $command = (new SyncCommandValidator(65536))->validate([
            'operationId' => '01912345-6789-7abc-8def-0123456789ab',
            'commandType' => 'inventory.count-line.upsert',
            'entityId' => '01912345-6789-7abc-9def-0123456789ab',
            'baseRevision' => 0,
            'clientTimestamp' => '2026-08-04T12:00:00+00:00',
            'payloadSchemaVersion' => 1,
            'payload' => [
                'sessionId' => '01912345-6789-7abc-adef-0123456789ab',
                'homeProductId' => '01912345-6789-7abc-bdef-0123456789ab',
                'quantity' => '4',
                'confidence' => null,
                'source' => 'manual',
                'notes' => '',
            ],
        ]);

        self::assertSame('inventory.count-line.upsert', $command->commandType);
        self::assertSame(0, $command->baseRevision);
    }

    public function testUnknownPayloadAndMissingAggregateRevisionAreRejected(): void
    {
        $validator = new SyncCommandValidator(65536);
        $value = [
            'operationId' => '01912345-6789-7abc-8def-0123456789ab',
            'commandType' => 'shopping.list-line.checked',
            'entityId' => '01912345-6789-7abc-9def-0123456789ab',
            'baseRevision' => null,
            'clientTimestamp' => '2026-08-04T12:00:00+00:00',
            'payloadSchemaVersion' => 1,
            'payload' => [
                'listId' => '01912345-6789-7abc-adef-0123456789ab',
                'checked' => true,
                'serverRevision' => 99,
            ],
        ];

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('serverRevision');
        $validator->validate($value);
    }

    public function testPrivateTaxonomyCommandsUseClosedRevisionedPayloads(): void
    {
        $validator = new SyncCommandValidator(65536);
        $category = $validator->validate($this->command(
            'inventory.home-category.update',
            3,
            ['name' => 'Shelf-stable', 'status' => 'active'],
        ));
        $product = $validator->validate($this->command(
            'inventory.home-product.update',
            5,
            [
                'privateName' => 'Sorghum meal',
                'originalPackText' => null,
                'homeCategoryId' => '01912345-6789-7abc-adef-0123456789ab',
                'status' => 'archived',
            ],
        ));

        self::assertSame(3, $category->baseRevision);
        self::assertSame(5, $product->baseRevision);
    }

    public function testPrivateProductUpdateRejectsUnrecognizedServerFields(): void
    {
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('serverRevision');
        (new SyncCommandValidator(65536))->validate($this->command(
            'inventory.home-product.update',
            1,
            [
                'privateName' => 'Sorghum meal',
                'originalPackText' => null,
                'homeCategoryId' => null,
                'status' => 'active',
                'serverRevision' => 9,
            ],
        ));
    }

    public function testShoppingLifecycleCommandsRequireClosedTypedRevisionedPayloads(): void
    {
        $validator = new SyncCommandValidator(65536);
        $list = $validator->validate($this->command(
            'shopping.list.update',
            3,
            ['name' => 'Month end', 'status' => 'archived'],
        ));
        $line = $validator->validate($this->command(
            'shopping.list-line.update',
            2,
            [
                'listId' => '01912345-6789-7abc-adef-0123456789ab',
                'description' => 'Brown rice',
                'quantity' => '3.5',
                'archived' => true,
            ],
        ));
        self::assertSame(3, $list->baseRevision);
        self::assertTrue($line->payload['archived']);
        $this->expectException(Problem::class);
        $validator->validate($this->command('shopping.list.update', 3, ['name' => null, 'status' => 'open']));
    }

    public function testManualSelectedPacksAndExplicitRelinkingUseRevisionedClosedPayloads(): void
    {
        $validator = new SyncCommandValidator(65536);
        $listId = '01912345-6789-7abc-adef-0123456789ab';
        $productId = '01912345-6789-7abc-bdef-0123456789ab';
        $packId = '01912345-6789-7abc-8def-1123456789ab';
        $created = $validator->validate($this->command('shopping.list-line.create', 1, [
            'listId' => $listId,
            'homeProductId' => $productId,
            'description' => 'Rice',
            'quantity' => '4',
            'selectedPackId' => $packId,
        ]));
        self::assertSame($packId, $created->payload['selectedPackId']);
        $linked = $validator->validate($this->command('shopping.list-line.relink', 2, [
            'listId' => $listId,
            'homeProductId' => $productId,
            'selectedPackId' => $packId,
            'reason' => 'Resolve custom line',
        ]));
        self::assertSame(2, $linked->baseRevision);
        $unlinked = $validator->validate($this->command('shopping.list-line.relink', 3, [
            'listId' => $listId,
            'homeProductId' => null,
            'selectedPackId' => null,
            'reason' => 'Keep a free-text instruction',
        ]));
        self::assertNull($unlinked->payload['homeProductId']);
        $this->expectException(Problem::class);
        $validator->validate($this->command('shopping.list-line.relink', 3, [
            'listId' => $listId,
            'homeProductId' => $productId,
            'reason' => 'Missing deliberate pack choice',
        ]));
    }

    public function testPlaceMetadataUpdatesRequireTypedValuesAndPositiveRevision(): void
    {
        $validator = new SyncCommandValidator(65536);
        $command = $validator->validate(
            $this->command('inventory.location.update', 3, [
                'name' => 'Cupboard',
                'kind' => 'shelf',
                'status' => 'active',
            ]),
        );
        self::assertSame(3, $command->baseRevision);
        self::assertSame('Cupboard', $command->payload['name']);
        $store = $validator->validate($this->command('purchasing.store.update', 1, ['location' => '']));
        self::assertSame('', $store->payload['location']);
        $this->expectException(Problem::class);
        $validator->validate($this->command('purchasing.store.update', 1, ['name' => null]));
    }

    public function testPlaceMetadataUpdatesRejectMissingChanges(): void
    {
        $this->expectException(Problem::class);
        new SyncCommandValidator(65536)->validate($this->command('inventory.location.update', 1, []));
    }

    public function testPlaceMetadataUpdatesRejectCreateRevision(): void
    {
        $this->expectException(Problem::class);
        new SyncCommandValidator(65536)->validate(
            $this->command('purchasing.store.update', 0, ['name' => 'Grocer']),
        );
    }

    public function testStockPreferenceCommandPreservesTypedPolicyAndRevision(): void
    {
        $payload = [
            'minimumQuantity' => '4.125',
            'alwaysKeep' => true,
            'neverSuggest' => false,
            'preferredPackId' => null,
            'leadTimeDays' => 2,
            'targetCoverageDays' => 14,
            'snoozeUntil' => null,
        ];
        $command = (new SyncCommandValidator(65536))->validate(
            $this->command('shopping.preference.put', 4, $payload),
        );
        self::assertSame(4, $command->baseRevision);
        self::assertSame($payload, $command->payload);
        $payload['leadTimeDays'] = '2';
        $this->expectException(Problem::class);
        (new SyncCommandValidator(65536))->validate($this->command('shopping.preference.put', 4, $payload));
    }

    public function testConfirmedPurchaseCommandRequiresClosedTypedStockInterpretation(): void
    {
        $payload = [
            'receiptId' => '01912345-6789-7abc-adef-0123456789ab',
            'homeProductId' => '01912345-6789-7abc-bdef-0123456789ab',
            'packId' => null,
            'rawDescription' => 'Apples',
            'quantity' => '5',
            'originalPackText' => null,
            'unitPrice' => null,
            'lineTotal' => '50',
            'unit' => 'kg',
            'stockQuantityPerPack' => '1',
        ];
        $validator = new SyncCommandValidator(65536);
        $command = $validator->validate($this->command('purchasing.receipt-line.purchase', 1, $payload));
        self::assertSame($payload, $command->payload);
        self::assertSame(1, $command->baseRevision);
        foreach ([0, null] as $revision) {
            try {
                $validator->validate($this->command('purchasing.receipt-line.purchase', $revision, $payload));
                self::fail('A confirmed purchase requires the receipt revision.');
            } catch (Problem $error) {
                self::assertSame(422, $error->status);
            }
        }
        $payload['stockQuantity'] = '5';
        $this->expectException(Problem::class);
        $validator->validate($this->command('purchasing.receipt-line.purchase', 1, $payload));
    }

    public function testPackReviewAcceptsAllFactsTogetherAndRejectsPartialSnapshots(): void
    {
        $payload = [
            'receiptId' => '01912345-6789-7abc-adef-0123456789ab',
            'homeProductId' => '01912345-6789-7abc-bdef-0123456789ab',
            'packId' => null,
            'unit' => 'kg',
            'stockQuantityPerPack' => '1',
        ];
        $validator = new SyncCommandValidator(65536);
        self::assertSame($payload, $validator->validate(
            $this->command('purchasing.receipt-line.approve', 1, $payload),
        )->payload);
        unset($payload['unit']);
        $this->expectException(Problem::class);
        $validator->validate($this->command('purchasing.receipt-line.approve', 1, $payload));
    }

    public function testCountCloseAndAuditedReviewAcceptExplicitObservationEvidence(): void
    {
        $validator = new SyncCommandValidator(65536);
        $evidence = [
            'scopeComplete' => true,
            'reliability' => 'reliable',
            'observedAt' => '2026-08-01T16:00:00+02:00',
        ];
        self::assertSame($evidence, $validator->validate(
            $this->command('inventory.count-session.close', 3, $evidence),
        )->payload);
        $review = [...$evidence, 'reason' => 'Verified original count sheet', 'lineCorrections' => [[
            'id' => '01912345-6789-7abc-8def-1123456789ab',
            'quantity' => '3.5',
            'expectedRevision' => 2,
        ]]];
        self::assertSame($review, $validator->validate(
            $this->command('inventory.count-session.review', 4, $review),
        )->payload);
    }

    public function testCountReviewRejectsMalformedNestedCorrectionAndTruthyCompleteness(): void
    {
        $validator = new SyncCommandValidator(65536);
        $payload = [
            'scopeComplete' => true, 'reliability' => 'reliable',
            'observedAt' => '2026-08-01T16:00:00+02:00', 'reason' => 'Reviewed',
        ];
        foreach (
            [
            [...$payload, 'scopeComplete' => 'true'],
            [...$payload, 'lineCorrections' => '3'],
            [...$payload, 'lineCorrections' => [['id' => 'not-a-uuid', 'quantity' => '3', 'expectedRevision' => 1]]],
            [...$payload, 'lineCorrections' => [[
                'id' => '01912345-6789-7abc-8def-1123456789ab', 'quantity' => '3', 'expectedRevision' => '1',
            ]]],
            ] as $invalid
        ) {
            try {
                $validator->validate($this->command('inventory.count-session.review', 4, $invalid));
                self::fail('An invalid count review command was accepted.');
            } catch (Problem $error) {
                self::assertSame(422, $error->status);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function command(string $commandType, ?int $revision, array $payload): array
    {
        return [
            'operationId' => '01912345-6789-7abc-8def-0123456789ab',
            'commandType' => $commandType,
            'entityId' => '01912345-6789-7abc-9def-0123456789ab',
            'baseRevision' => $revision,
            'clientTimestamp' => '2026-08-24T12:00:00+00:00',
            'payloadSchemaVersion' => 1,
            'payload' => $payload,
        ];
    }
}
