<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\HomePreferenceSyncEntityPolicy;
use Providentia\Synchronization\Application\PrivateNoteSyncEntityPolicy;
use Providentia\Synchronization\Application\SyncEntityPolicyRegistry;
use Providentia\Synchronization\Application\SyncOperationValidator;

final class SyncOperationValidatorTest extends TestCase
{
    private const OPERATION_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const ENTITY_ID = '01912345-6789-7abc-9def-0123456789ab';

    public function testValidPrivateNoteBecomesTypedOperation(): void
    {
        $operation = $this->validator()->validate($this->operation());

        self::assertSame(self::OPERATION_ID, $operation->operationId);
        self::assertSame('private-note', $operation->entityType);
        self::assertNull($operation->baseRevision);
        self::assertSame(['body' => 'freezer'], $operation->payload);
        self::assertSame($this->operation(), $operation->requestShape());
    }

    public function testValidDeleteRequiresAnEmptyPayload(): void
    {
        $payload = $this->operation();
        $payload['operationType'] = 'delete';
        $payload['baseRevision'] = 3;
        $payload['payload'] = [];

        $operation = $this->validator()->validate($payload);

        self::assertSame('delete', $operation->operationType);
        self::assertSame([], $operation->payload);
    }

    public function testSchemaVersionUsesStrictIntegerSemantics(): void
    {
        $payload = $this->operation();
        $payload['payloadSchemaVersion'] = '1';

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('integer 1');
        $this->validator()->validate($payload);
    }

    public function testUnknownFieldsAndArrayPayloadsAreRejected(): void
    {
        $payload = $this->operation();
        $payload['serverRevision'] = 4;

        try {
            $this->validator()->validate($payload);
            self::fail('A server-owned operation field was accepted.');
        } catch (Problem $problem) {
            self::assertStringContainsString('serverRevision', $problem->getMessage());
        }

        $payload = $this->operation();
        $payload['payload'] = ['first', 'second'];
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('payload must be an object');
        $this->validator()->validate($payload);
    }

    public function testTimestampAndPayloadSizeAreBounded(): void
    {
        $payload = $this->operation();
        $payload['clientTimestamp'] = '2026-07-30';

        try {
            $this->validator()->validate($payload);
            self::fail('A date without RFC 3339 time and offset was accepted.');
        } catch (Problem $problem) {
            self::assertStringContainsString('RFC 3339', $problem->getMessage());
        }

        $payload = $this->operation();
        $payload['payload'] = ['body' => str_repeat('x', 20)];
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('payload is too large');
        $this->validator(10)->validate($payload);
    }

    public function testInvalidPayloadLimitFailsAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator(1);
    }

    private function validator(int $maxPayloadBytes = 65536): SyncOperationValidator
    {
        return new SyncOperationValidator(
            new SyncEntityPolicyRegistry([
                new PrivateNoteSyncEntityPolicy(),
                new HomePreferenceSyncEntityPolicy(),
            ]),
            $maxPayloadBytes,
        );
    }

    /** @return array<string, mixed> */
    private function operation(): array
    {
        return [
            'operationId' => self::OPERATION_ID,
            'entityType' => 'private-note',
            'entityId' => self::ENTITY_ID,
            'operationType' => 'put',
            'baseRevision' => null,
            'clientTimestamp' => '2026-07-30T11:59:00+00:00',
            'payloadSchemaVersion' => 1,
            'payload' => ['body' => 'freezer'],
        ];
    }
}
