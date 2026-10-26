<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomePermission;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\SyncCommandValidator;
use Providentia\Synchronization\Application\SyncReadPolicy;

final class FinishedReceiptCorrectionSyncTest extends TestCase
{
    public function testOmittedAndPendingLinesPreserveDistinctRevisionEvidence(): void
    {
        foreach ([null, 3] as $lineRevision) {
            $value = $this->command();
            $value['payload']['expectedLineRevision'] = $lineRevision;
            $command = new SyncCommandValidator(65536)->validate($value);
            self::assertSame(8, $command->baseRevision);
            self::assertSame($lineRevision, $command->payload['expectedLineRevision']);
            self::assertSame($value['entityId'], $command->entityId);
            self::assertSame($value['payload']['correctionId'], $command->payload['correctionId']);
        }
    }

    #[DataProvider('invalidEvidence')]
    public function testMalformedCorrectionEvidenceIsRejected(string $field, mixed $value): void
    {
        $command = $this->command();
        if ($field === 'baseRevision') {
            $command['baseRevision'] = $value;
        } else {
            $command['payload'][$field] = $value;
        }
        $this->expectException(Problem::class);
        new SyncCommandValidator(65536)->validate($command);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidEvidence(): iterable
    {
        yield 'missing receipt revision' => ['baseRevision', null];
        yield 'zero receipt revision' => ['baseRevision', 0];
        yield 'zero line revision' => ['expectedLineRevision', 0];
        yield 'text line revision' => ['expectedLineRevision', '3'];
        yield 'boolean line revision' => ['expectedLineRevision', true];
        yield 'invalid correction identity' => ['correctionId', 'another-operation'];
        yield 'null receipt identity' => ['receiptId', null];
        yield 'null product identity' => ['homeProductId', null];
        yield 'null reason' => ['reason', null];
        yield 'numeric quantity' => ['quantity', 2];
        yield 'server owned status' => ['approvalStatus', 'approved'];
    }

    public function testMissingFieldsCannotBeReplacedByServerDefaults(): void
    {
        $command = $this->command();
        unset($command['payload']['expectedLineRevision']);
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('expectedLineRevision');
        new SyncCommandValidator(65536)->validate($command);
    }

    public function testCorrectionAuditAndAcknowledgementRequirePurchasesRead(): void
    {
        self::assertSame('purchasing-receipt-line', SyncReadPolicy::entityForCommand(
            'purchasing.receipt-line.correct',
        ));
        self::assertTrue(SyncReadPolicy::canRead(
            'purchasing-receipt-line-correction',
            [HomePermission::PURCHASES_READ],
        ));
        self::assertFalse(SyncReadPolicy::canRead(
            'purchasing-receipt-line-correction',
            [HomePermission::INVENTORY_READ],
        ));
        $acknowledgement = [
            'operationId' => 'correction-operation',
            'status' => 'accepted',
            'commandType' => 'purchasing.receipt-line.correct',
            'result' => ['lineTotal' => '12.00', 'reason' => 'Omitted purchase'],
        ];
        self::assertSame($acknowledgement, SyncReadPolicy::result(
            $acknowledgement,
            [HomePermission::PURCHASES_READ],
        ));
        self::assertSame([
            'operationId' => 'correction-operation', 'status' => 'accepted',
        ], SyncReadPolicy::result($acknowledgement, [HomePermission::INVENTORY_READ]));
    }

    /** @return array<string, mixed> */
    private function command(): array
    {
        return [
            'operationId' => '01912345-6789-7abc-8def-0123456789ab',
            'commandType' => 'purchasing.receipt-line.correct',
            'entityId' => '01912345-6789-7abc-9def-0123456789ab',
            'baseRevision' => 8,
            'clientTimestamp' => '2026-10-26T09:00:00Z',
            'payloadSchemaVersion' => 1,
            'payload' => [
                'receiptId' => '01912345-6789-7abc-adef-0123456789ab',
                'correctionId' => '01912345-6789-7abc-bdef-0123456789ab',
                'expectedLineRevision' => null,
                'reason' => 'Omitted purchase',
                'homeProductId' => '01912345-6789-7abc-8def-1123456789ab',
                'packId' => null,
                'rawDescription' => 'Rice',
                'quantity' => '2',
                'originalPackText' => '1 kg',
                'unitPrice' => '6.00',
                'lineTotal' => '12.00',
                'unit' => 'kg',
                'stockQuantityPerPack' => '1',
            ],
        ];
    }
}
