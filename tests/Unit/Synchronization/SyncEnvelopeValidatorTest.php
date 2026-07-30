<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\SyncEnvelopeValidator;

final class SyncEnvelopeValidatorTest extends TestCase
{
    private const DEVICE_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const BATCH_ID = '01912345-6789-7abc-9def-0123456789ab';

    public function testValidEnvelopeIsNormalizedIntoAValueObject(): void
    {
        $validator = new SyncEnvelopeValidator(2);
        $envelope = $validator->validate(
            self::DEVICE_ID,
            strtoupper(self::BATCH_ID),
            $this->envelope(),
        );

        self::assertSame(self::BATCH_ID, $envelope->batchId);
        self::assertSame(self::DEVICE_ID, $envelope->deviceId);
        self::assertNull($envelope->lastPulledCursor);
        self::assertCount(1, $envelope->operations);
    }

    public function testUnknownFieldsAreRejectedBeforeOperationProcessing(): void
    {
        $validator = new SyncEnvelopeValidator(1);
        $payload = $this->envelope();
        $payload['homeId'] = 'client-controlled-scope';

        try {
            $validator->validate(self::DEVICE_ID, self::BATCH_ID, $payload);
            self::fail('An unknown envelope field was accepted.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
            self::assertStringContainsString('homeId', $problem->getMessage());
        }
    }

    public function testDeviceAndIdempotencyBindingAreIndependentGuards(): void
    {
        $validator = new SyncEnvelopeValidator(1);
        $payload = $this->envelope();
        $payload['deviceId'] = '01912345-6789-7abc-8def-1123456789ab';

        try {
            $validator->validate(self::DEVICE_ID, self::BATCH_ID, $payload);
            self::fail('Another session device was accepted.');
        } catch (Problem $problem) {
            self::assertSame(403, $problem->status);
        }

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('Idempotency-Key must equal batchId');
        $validator->validate(
            self::DEVICE_ID,
            '01912345-6789-7abc-adef-0123456789ab',
            $this->envelope(),
        );
    }

    public function testBatchBoundsAndCursorTypeAreEnforced(): void
    {
        $validator = new SyncEnvelopeValidator(1);
        $payload = $this->envelope();
        $payload['operations'] = [];

        try {
            $validator->validate(self::DEVICE_ID, self::BATCH_ID, $payload);
            self::fail('An empty operation batch was accepted.');
        } catch (Problem $problem) {
            self::assertSame('Invalid batch', $problem->title);
        }

        $payload = $this->envelope();
        $payload['lastPulledCursor'] = 42;
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('opaque string or null');
        $validator->validate(self::DEVICE_ID, self::BATCH_ID, $payload);
    }

    public function testNonPositiveBatchConfigurationFailsFast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SyncEnvelopeValidator(0);
    }

    /** @return array<string, mixed> */
    private function envelope(): array
    {
        return [
            'protocolVersion' => 1,
            'batchId' => self::BATCH_ID,
            'deviceId' => self::DEVICE_ID,
            'lastPulledCursor' => null,
            'operations' => [['operationId' => 'raw-operation']],
        ];
    }
}
