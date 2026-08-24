<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\SyncCommandValidator;

final class SyncCommandValidatorBoundaryTest extends TestCase
{
    private const OPERATION_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const ENTITY_ID = '01912345-6789-7abc-9def-0123456789ab';
    private const RELATED_ID = '01912345-6789-7abc-adef-0123456789ab';
    private const SECOND_RELATED_ID = '01912345-6789-7abc-bdef-0123456789ab';

    /** @param array<string, mixed> $payload */
    #[DataProvider('validCommands')]
    public function testEveryProtocolTwoCommandShapeIsAccepted(
        string $type,
        array $payload,
        ?int $baseRevision,
    ): void {
        $command = (new SyncCommandValidator(65_536))->validate(
            $this->command($type, $payload, $baseRevision),
        );

        self::assertSame($type, $command->commandType);
        self::assertSame($payload, $command->payload);
        self::assertSame($baseRevision, $command->baseRevision);
        self::assertSame(1, $command->payloadSchemaVersion);
    }

    /** @return iterable<string, array{string, array<string, mixed>, int|null}> */
    public static function validCommands(): iterable
    {
        yield 'inventory location' => [
            'inventory.location.create',
            ['name' => 'Pantry', 'kind' => 'pantry'],
            null,
        ];
        yield 'home product' => [
            'inventory.home-product.create',
            [
                'productId' => self::RELATED_ID,
                'packId' => self::SECOND_RELATED_ID,
                'privateName' => null,
                'originalPackText' => null,
            ],
            0,
        ];
        yield 'adjustment' => [
            'inventory.adjustment.create',
            ['quantityDelta' => '-1.5', 'reason' => 'used'],
            null,
        ];
        yield 'count session' => [
            'inventory.count-session.create',
            [
                'locationId' => self::RELATED_ID,
                'notes' => '',
                'scopeComplete' => true,
                'reliability' => 'reliable',
            ],
            0,
        ];
        yield 'count line' => [
            'inventory.count-line.upsert',
            [
                'sessionId' => self::RELATED_ID,
                'homeProductId' => self::SECOND_RELATED_ID,
                'quantity' => '4',
                'confidence' => null,
                'source' => 'photo-confirmed',
                'notes' => '',
            ],
            1,
        ];
        yield 'count close' => ['inventory.count-session.close', [], 1];
        yield 'count cancel' => ['inventory.count-session.cancel', [], 1];
        yield 'store' => [
            'purchasing.store.create',
            ['name' => 'Market', 'location' => 'Town'],
            null,
        ];
        yield 'receipt' => [
            'purchasing.receipt.create',
            [
                'storeId' => self::RELATED_ID,
                'purchaseDate' => '2026-08-04',
                'currency' => 'NAD',
                'totalAmount' => '12.50',
                'notes' => '',
                'sourceReference' => null,
            ],
            0,
        ];
        yield 'receipt line' => [
            'purchasing.receipt-line.create',
            [
                'receiptId' => self::RELATED_ID,
                'rawDescription' => 'Oats',
                'quantity' => '1',
                'originalPackText' => '500 g',
                'unitPrice' => '12.50',
                'lineTotal' => '12.50',
            ],
            1,
        ];
        yield 'receipt approval' => [
            'purchasing.receipt-line.approve',
            ['receiptId' => self::RELATED_ID, 'homeProductId' => self::SECOND_RELATED_ID],
            1,
        ];
        yield 'receipt unresolved' => [
            'purchasing.receipt-line.unresolve',
            ['receiptId' => self::RELATED_ID],
            1,
        ];
        yield 'receipt commit' => ['purchasing.receipt.commit', [], 1];
        yield 'shopping list' => [
            'shopping.list.create',
            ['name' => 'Weekly', 'kind' => 'manual'],
            0,
        ];
        yield 'shopping line' => [
            'shopping.list-line.create',
            [
                'listId' => self::RELATED_ID,
                'homeProductId' => self::SECOND_RELATED_ID,
                'description' => 'Oats',
                'quantity' => '2',
            ],
            1,
        ];
        yield 'shopping checked' => [
            'shopping.list-line.checked',
            ['listId' => self::RELATED_ID, 'checked' => true],
            1,
        ];
    }

    #[DataProvider('requiredCommandFields')]
    public function testEveryRequiredCommandFieldIsEnforced(string $missingField): void
    {
        $command = $this->command('shopping.list.create', ['name' => 'Weekly', 'kind' => 'manual'], null);
        unset($command[$missingField]);

        $problem = $this->problem(fn () => (new SyncCommandValidator(65_536))->validate($command));

        self::assertSame(422, $problem->status);
        self::assertStringContainsString($missingField, $problem->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function requiredCommandFields(): iterable
    {
        $fields = [
            'operationId',
            'commandType',
            'entityId',
            'clientTimestamp',
            'payloadSchemaVersion',
            'payload',
        ];
        foreach ($fields as $field) {
            yield $field => [$field];
        }
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('uuidPayloadFields')]
    public function testEveryTypedUuidPayloadFieldIsValidated(
        string $type,
        array $payload,
        string $field,
        ?int $baseRevision,
    ): void {
        $payload[$field] = 'not-a-uuid';

        $problem = $this->problem(
            fn () => (new SyncCommandValidator(65_536))->validate(
                $this->command($type, $payload, $baseRevision),
            ),
        );

        self::assertSame(422, $problem->status);
        self::assertStringContainsString($field, $problem->getMessage());
    }

    /** @return iterable<string, array{string, array<string, mixed>, string, int|null}> */
    public static function uuidPayloadFields(): iterable
    {
        $cases = iterator_to_array(self::validCommands());
        $fields = [
            'home product product' => ['home product', 'productId'],
            'count session location' => ['count session', 'locationId'],
            'count line session' => ['count line', 'sessionId'],
            'receipt store' => ['receipt', 'storeId'],
            'receipt line receipt' => ['receipt line', 'receiptId'],
            'receipt approval product' => ['receipt approval', 'homeProductId'],
            'receipt unresolved receipt' => ['receipt unresolved', 'receiptId'],
            'shopping line product' => ['shopping line', 'homeProductId'],
            'shopping checked list' => ['shopping checked', 'listId'],
        ];
        foreach ($fields as $label => [$case, $field]) {
            [$type, $payload, $baseRevision] = $cases[$case];
            yield $label => [$type, $payload, $field, $baseRevision];
        }
    }

    public function testRequiredUnresolvedReceiptReferenceRejectsAnEmptyUuid(): void
    {
        $problem = $this->problem(
            fn () => (new SyncCommandValidator(65_536))->validate(
                $this->command(
                    'purchasing.receipt-line.unresolve',
                    ['receiptId' => ''],
                    1,
                ),
            ),
        );

        self::assertSame(422, $problem->status);
        self::assertStringContainsString('receiptId', $problem->getMessage());
    }

    #[DataProvider('requiredPayloadFields')]
    public function testMutationSensitiveRequiredPayloadFieldsAreEnforced(
        string $type,
        string $missingField,
    ): void {
        $cases = iterator_to_array(self::validCommands());
        $case = match ($type) {
            'inventory.count-line.upsert' => $cases['count line'],
            'purchasing.receipt-line.unresolve' => $cases['receipt unresolved'],
            'shopping.list.create' => $cases['shopping list'],
            'shopping.list-line.checked' => $cases['shopping checked'],
            default => throw new InvalidArgumentException('Unsupported test command type.'),
        };
        [$commandType, $payload, $baseRevision] = $case;
        unset($payload[$missingField]);

        $problem = $this->problem(
            fn () => (new SyncCommandValidator(65_536))->validate(
                $this->command($commandType, $payload, $baseRevision),
            ),
        );

        self::assertStringContainsString($missingField, $problem->getMessage());
    }

    /** @return iterable<string, array{string, string}> */
    public static function requiredPayloadFields(): iterable
    {
        yield 'count session reference' => ['inventory.count-line.upsert', 'sessionId'];
        yield 'unresolved receipt reference' => ['purchasing.receipt-line.unresolve', 'receiptId'];
        yield 'shopping list name' => ['shopping.list.create', 'name'];
        yield 'checked list reference' => ['shopping.list-line.checked', 'listId'];
    }

    public function testCreateAndUpdateRevisionRulesAreExact(): void
    {
        $validator = new SyncCommandValidator(65_536);
        $create = $this->command('shopping.list.create', ['name' => 'Weekly', 'kind' => 'manual'], 0);
        self::assertSame(0, $validator->validate($create)->baseRevision);

        $create['baseRevision'] = 1;
        self::assertStringContainsString(
            'null or zero',
            $this->problem(fn () => $validator->validate($create))->getMessage(),
        );
        $create['baseRevision'] = '0';
        self::assertStringContainsString(
            'non-negative integer',
            $this->problem(fn () => $validator->validate($create))->getMessage(),
        );
        $create['baseRevision'] = -1;
        self::assertStringContainsString(
            'non-negative integer',
            $this->problem(fn () => $validator->validate($create))->getMessage(),
        );

        $update = $this->command('shopping.list-line.checked', [
            'listId' => self::RELATED_ID,
            'checked' => true,
        ], null);
        self::assertStringContainsString(
            'requires baseRevision',
            $this->problem(fn () => $validator->validate($update))->getMessage(),
        );
    }

    public function testClosedObjectsBooleanAndStringTypesAreEnforced(): void
    {
        $validator = new SyncCommandValidator(65_536);
        $unknownCommand = $this->command(
            'shopping.list.create',
            ['name' => 'Weekly', 'kind' => 'manual'],
            null,
        );
        $unknownCommand['zField'] = 'z';
        $unknownCommand['aField'] = 'a';
        $problem = $this->problem(fn () => $validator->validate($unknownCommand));
        self::assertSame(422, $problem->status);
        self::assertStringContainsString('aField, zField', $problem->getMessage());

        $unknown = $this->command('shopping.list.create', [
            'name' => 'Weekly',
            'kind' => 'manual',
            'zField' => 'z',
            'aField' => 'a',
        ], null);
        $problem = $this->problem(fn () => $validator->validate($unknown));
        self::assertSame(422, $problem->status);
        self::assertStringContainsString('aField, zField', $problem->getMessage());

        $boolean = $this->command('shopping.list-line.checked', [
            'listId' => self::RELATED_ID,
            'checked' => 'true',
        ], 1);
        self::assertStringContainsString(
            'checked must be boolean',
            $this->problem(fn () => $validator->validate($boolean))->getMessage(),
        );

        $string = $this->command('shopping.list.create', ['name' => 42, 'kind' => 'manual'], null);
        self::assertStringContainsString(
            'name must be a string or null',
            $this->problem(fn () => $validator->validate($string))->getMessage(),
        );
    }

    public function testUuidAndTimestampAnchorsAndCanonicalizationAreObservable(): void
    {
        $validator = new SyncCommandValidator(65_536);
        $valid = $this->command('shopping.list.create', ['name' => 'Weekly', 'kind' => 'manual'], null);
        $valid['operationId'] = strtoupper(self::OPERATION_ID);
        $valid['entityId'] = strtoupper(self::ENTITY_ID);
        $command = $validator->validate($valid);
        self::assertSame(self::OPERATION_ID, $command->operationId);
        self::assertSame(self::ENTITY_ID, $command->entityId);

        foreach (['x' . self::OPERATION_ID, self::OPERATION_ID . 'x'] as $invalidId) {
            $invalid = $valid;
            $invalid['operationId'] = $invalidId;
            self::assertSame(422, $this->problem(fn () => $validator->validate($invalid))->status);
        }
        $nonStringId = $valid;
        $nonStringId['operationId'] = 42;
        self::assertSame(422, $this->problem(fn () => $validator->validate($nonStringId))->status);
        $invalidTimestamps = [
            'x2026-08-04T12:00:00+00:00',
            '2026-08-04T12:00:00+00:00x',
        ];
        foreach ($invalidTimestamps as $invalidTimestamp) {
            $invalid = $valid;
            $invalid['clientTimestamp'] = $invalidTimestamp;
            self::assertStringContainsString(
                'RFC 3339',
                $this->problem(fn () => $validator->validate($invalid))->getMessage(),
            );
        }
        $nonStringTimestamp = $valid;
        $nonStringTimestamp['clientTimestamp'] = 42;
        self::assertStringContainsString(
            'RFC 3339',
            $this->problem(fn () => $validator->validate($nonStringTimestamp))->getMessage(),
        );

        $wrongSchema = $valid;
        $wrongSchema['payloadSchemaVersion'] = '1';
        self::assertStringContainsString(
            'integer 1',
            $this->problem(fn () => $validator->validate($wrongSchema))->getMessage(),
        );
    }

    public function testPayloadByteBoundaryAndConstructorMinimumAreExact(): void
    {
        $payload = ['quantityDelta' => '1', 'reason' => str_repeat('x', 20)];
        $encodedBytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        $command = $this->command('inventory.adjustment.create', $payload, null);

        self::assertSame(
            $payload,
            (new SyncCommandValidator($encodedBytes))->validate($command)->payload,
        );
        self::assertStringContainsString(
            'too large',
            $this->problem(
                fn () => (new SyncCommandValidator($encodedBytes - 1))->validate($command),
            )->getMessage(),
        );
        self::assertSame(
            [],
            (new SyncCommandValidator(2))->validate(
                $this->command('inventory.count-session.close', [], 1),
            )->payload,
        );

        $this->expectException(InvalidArgumentException::class);
        new SyncCommandValidator(1);
    }

    public function testUnknownCommandAndNonObjectPayloadsFailWithExpectedProblems(): void
    {
        $validator = new SyncCommandValidator(65_536);
        $unknown = $this->command('unknown.command', [], null);
        self::assertStringContainsString(
            'not enabled',
            $this->problem(fn () => $validator->validate($unknown))->getMessage(),
        );

        foreach ([['one'], 'not-an-object'] as $payload) {
            $invalid = $this->command('shopping.list.create', [], null);
            $invalid['payload'] = $payload;
            self::assertStringContainsString(
                'payload must be an object',
                $this->problem(fn () => $validator->validate($invalid))->getMessage(),
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function command(string $type, array $payload, ?int $baseRevision): array
    {
        return [
            'operationId' => self::OPERATION_ID,
            'commandType' => $type,
            'entityId' => self::ENTITY_ID,
            'baseRevision' => $baseRevision,
            'clientTimestamp' => '2026-08-04T12:00:00+00:00',
            'payloadSchemaVersion' => 1,
            'payload' => $payload,
        ];
    }

    /** @param callable(): mixed $operation */
    private function problem(callable $operation): Problem
    {
        try {
            $operation();
        } catch (Problem $problem) {
            return $problem;
        }
        self::fail('Expected an application problem.');
    }
}
