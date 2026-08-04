<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\SyncCommandValidator;

final class SyncCommandValidatorTest extends TestCase
{
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
}
