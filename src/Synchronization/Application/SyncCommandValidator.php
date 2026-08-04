<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use DateTimeImmutable;
use Providentia\SharedKernel\Application\Problem;
use Throwable;

/**
 * Validates protocol-v2 commands before any domain service is invoked.
 *
 * Every payload is a closed object. Domain services remain responsible for
 * business validation; this boundary rejects malformed and server-owned data.
 */
final class SyncCommandValidator
{
    private const FIELDS = [
        'operationId',
        'commandType',
        'entityId',
        'baseRevision',
        'clientTimestamp',
        'payloadSchemaVersion',
        'payload',
    ];

    private const REQUIRED_FIELDS = [
        'operationId',
        'commandType',
        'entityId',
        'clientTimestamp',
        'payloadSchemaVersion',
        'payload',
    ];

    public function __construct(private readonly int $maxPayloadBytes)
    {
        if ($this->maxPayloadBytes < 2) {
            throw new \InvalidArgumentException('maxPayloadBytes must allow a JSON object.');
        }
    }

    /** @param array<string, mixed> $value */
    public function validate(array $value): SyncCommand
    {
        $this->rejectUnknownKeys($value, self::FIELDS, 'command');
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $value)) {
                throw new Problem(422, 'Invalid command', 'Missing command field: ' . $field);
            }
        }

        $operationId = $this->uuid($value['operationId'], 'operationId');
        $entityId = $this->uuid($value['entityId'], 'entityId');
        $commandType = $value['commandType'];
        if (! is_string($commandType)) {
            throw new Problem(422, 'Invalid command', 'commandType must be a string.');
        }
        $baseRevision = $value['baseRevision'] ?? null;
        if ($baseRevision !== null && (! is_int($baseRevision) || $baseRevision < 0)) {
            throw new Problem(422, 'Invalid command', 'baseRevision must be a non-negative integer or null.');
        }
        if ($value['payloadSchemaVersion'] !== 1) {
            throw new Problem(422, 'Invalid command', 'payloadSchemaVersion must be integer 1.');
        }
        $payload = $value['payload'];
        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new Problem(422, 'Invalid command', 'payload must be an object.');
        }
        if (count($payload) > 32 || strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > $this->maxPayloadBytes) {
            throw new Problem(422, 'Invalid command', 'The command payload is too large.');
        }

        $this->validatePayload($commandType, $payload, $baseRevision);

        return new SyncCommand(
            $operationId,
            $commandType,
            $entityId,
            $baseRevision,
            $this->timestamp($value['clientTimestamp']),
            1,
            $payload,
        );
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(string $type, array $payload, ?int $baseRevision): void
    {
        match ($type) {
            'inventory.location.create' => $this->shape(
                $payload,
                ['name', 'kind'],
                ['name', 'kind'],
                false,
                $baseRevision,
            ),
            'inventory.home-product.create' => $this->shape(
                $payload,
                ['productId', 'packId', 'privateName', 'originalPackText'],
                ['productId', 'packId', 'privateName', 'originalPackText'],
                false,
                $baseRevision,
            ),
            'inventory.adjustment.create' => $this->shape(
                $payload,
                ['quantityDelta', 'reason'],
                ['quantityDelta', 'reason'],
                false,
                $baseRevision,
            ),
            'inventory.count-session.create' => $this->shape(
                $payload,
                ['locationId', 'notes', 'scopeComplete', 'reliability'],
                ['locationId', 'notes', 'scopeComplete', 'reliability'],
                false,
                $baseRevision,
            ),
            'inventory.count-line.upsert' => $this->shape(
                $payload,
                ['sessionId', 'homeProductId', 'quantity', 'confidence', 'source', 'notes'],
                ['sessionId', 'homeProductId', 'quantity', 'confidence', 'source', 'notes'],
                true,
                $baseRevision,
            ),
            'inventory.count-session.close' => $this->shape($payload, [], [], true, $baseRevision),
            'purchasing.store.create' => $this->shape(
                $payload,
                ['name', 'location'],
                ['name', 'location'],
                false,
                $baseRevision,
            ),
            'purchasing.receipt.create' => $this->shape(
                $payload,
                ['storeId', 'purchaseDate', 'currency', 'totalAmount', 'notes', 'sourceReference'],
                ['storeId', 'purchaseDate', 'currency', 'totalAmount', 'notes', 'sourceReference'],
                false,
                $baseRevision,
            ),
            'purchasing.receipt-line.create' => $this->shape(
                $payload,
                ['receiptId', 'rawDescription', 'quantity', 'originalPackText', 'unitPrice', 'lineTotal'],
                ['receiptId', 'rawDescription', 'quantity', 'originalPackText', 'unitPrice', 'lineTotal'],
                true,
                $baseRevision,
            ),
            'purchasing.receipt-line.approve' => $this->shape(
                $payload,
                ['receiptId', 'homeProductId'],
                ['receiptId', 'homeProductId'],
                true,
                $baseRevision,
            ),
            'purchasing.receipt.commit' => $this->shape($payload, [], [], true, $baseRevision),
            'shopping.list.create' => $this->shape(
                $payload,
                ['name', 'kind'],
                ['name', 'kind'],
                false,
                $baseRevision,
            ),
            'shopping.list-line.create' => $this->shape(
                $payload,
                ['listId', 'homeProductId', 'description', 'quantity'],
                ['listId', 'homeProductId', 'description', 'quantity'],
                true,
                $baseRevision,
            ),
            'shopping.list-line.checked' => $this->shape(
                $payload,
                ['listId', 'checked'],
                ['listId', 'checked'],
                true,
                $baseRevision,
            ),
            default => throw new Problem(422, 'Invalid command', 'commandType is not enabled for synchronization.'),
        };

        $this->validateFieldTypes($type, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $allowed
     * @param list<string> $required
     */
    private function shape(
        array $payload,
        array $allowed,
        array $required,
        bool $revisionRequired,
        ?int $baseRevision,
    ): bool {
        $this->rejectUnknownKeys($payload, $allowed, 'payload');
        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new Problem(422, 'Invalid command', 'Missing payload field: ' . $field);
            }
        }
        if ($revisionRequired && $baseRevision === null) {
            throw new Problem(422, 'Invalid command', 'This command requires baseRevision.');
        }
        if (! $revisionRequired && $baseRevision !== null && $baseRevision !== 0) {
            throw new Problem(422, 'Invalid command', 'A create command baseRevision must be null or zero.');
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function validateFieldTypes(string $type, array $payload): void
    {
        $uuidFields = match ($type) {
            'inventory.home-product.create' => ['productId', 'packId'],
            'inventory.count-session.create' => ['locationId'],
            'inventory.count-line.upsert' => ['sessionId', 'homeProductId'],
            'purchasing.receipt.create' => ['storeId'],
            'purchasing.receipt-line.create' => ['receiptId'],
            'purchasing.receipt-line.approve' => ['receiptId', 'homeProductId'],
            'shopping.list-line.create' => ['listId', 'homeProductId'],
            'shopping.list-line.checked' => ['listId'],
            default => [],
        };
        foreach ($uuidFields as $field) {
            $fieldValue = $payload[$field] ?? null;
            if ($fieldValue !== null && $fieldValue !== '') {
                $this->uuid($fieldValue, $field);
            }
        }

        foreach ($payload as $field => $fieldValue) {
            if (in_array($field, ['scopeComplete', 'checked'], true) && ! is_bool($fieldValue)) {
                throw new Problem(422, 'Invalid command', $field . ' must be boolean.');
            }
            if (! in_array($field, $uuidFields, true) && ! in_array($field, ['scopeComplete', 'checked'], true)) {
                if ($fieldValue !== null && ! is_string($fieldValue)) {
                    throw new Problem(422, 'Invalid command', $field . ' must be a string or null.');
                }
            }
        }
    }

    /** @param list<string> $allowed @param array<string, mixed> $value */
    private function rejectUnknownKeys(array $value, array $allowed, string $label): void
    {
        $unknown = array_values(array_diff(array_keys($value), $allowed));
        if ($unknown === []) {
            return;
        }
        sort($unknown);
        throw new Problem(
            422,
            'Invalid command',
            sprintf('Synchronization %s contains unknown fields: %s.', $label, implode(', ', $unknown)),
        );
    }

    private function uuid(mixed $value, string $field): string
    {
        if (
            ! is_string($value)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1
        ) {
            throw new Problem(422, 'Invalid identifier', $field . ' must be a UUID.');
        }

        return strtolower($value);
    }

    private function timestamp(mixed $value): string
    {
        if (
            ! is_string($value)
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                $value,
            ) !== 1
        ) {
            throw new Problem(422, 'Invalid command', 'clientTimestamp must be an RFC 3339 timestamp.');
        }
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new Problem(422, 'Invalid command', 'clientTimestamp must be an RFC 3339 timestamp.');
        }

        return $value;
    }
}
