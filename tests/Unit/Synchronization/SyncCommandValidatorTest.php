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
