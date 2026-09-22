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
            'inventory.home-category.create' => $this->shape($payload, ['name'], ['name'], false, $baseRevision),
            'inventory.home-category.update' => $this->shape(
                $payload,
                ['name', 'status'],
                ['name', 'status'],
                true,
                $baseRevision,
            ),
            'inventory.location.update' => $this->shape($payload, ['name', 'kind', 'status'], [], true, $baseRevision),
            'inventory.location.create' => $this->shape(
                $payload,
                ['name', 'kind'],
                ['name', 'kind'],
                false,
                $baseRevision,
            ),
            'inventory.home-product.create' => $this->shape(
                $payload,
                ['productId', 'packId', 'privateName', 'originalPackText',
                    'homeCategoryId', 'globalCategoryId', 'unit'],
                ['productId', 'packId', 'privateName', 'originalPackText', 'homeCategoryId'],
                false,
                $baseRevision,
            ),
            'inventory.home-product.update' => $this->shape(
                $payload,
                ['privateName', 'originalPackText', 'homeCategoryId', 'status', 'globalCategoryId', 'unit'],
                [],
                true,
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
            'inventory.count-line.remove' => $this->shape($payload, ['sessionId'], ['sessionId'], true, $baseRevision),
            'inventory.count-session.close' => $this->shape($payload, [], [], true, $baseRevision),
            'inventory.count-session.cancel' => $this->shape($payload, [], [], true, $baseRevision),
            'purchasing.store.update' => $this->shape(
                $payload,
                ['name', 'location', 'status'],
                [],
                true,
                $baseRevision,
            ),
            'purchasing.store.create' => $this->shape(
                $payload,
                ['name', 'location'],
                ['name', 'location'],
                false,
                $baseRevision,
            ),
            'purchasing.receipt.update' => $this->shape(
                $payload,
                ['storeId', 'purchaseDate', 'currency', 'totalAmount', 'notes'],
                ['storeId', 'purchaseDate', 'currency', 'totalAmount', 'notes'],
                true,
                $baseRevision,
            ),
            'purchasing.receipt.cancel' => $this->shape($payload, [], [], true, $baseRevision),
            'purchasing.receipt-line.update' => $this->shape(
                $payload,
                ['receiptId', 'rawDescription', 'quantity', 'originalPackText', 'unitPrice', 'lineTotal'],
                ['receiptId', 'rawDescription', 'quantity', 'originalPackText', 'unitPrice', 'lineTotal'],
                true,
                $baseRevision,
            ),
            'purchasing.receipt-line.remove' => $this->shape(
                $payload,
                ['receiptId'],
                ['receiptId'],
                true,
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
            'purchasing.receipt-line.unresolve' => $this->shape(
                $payload,
                ['receiptId'],
                ['receiptId'],
                true,
                $baseRevision,
            ),
            'purchasing.receipt.commit' => $this->shape($payload, [], [], true, $baseRevision),
            'shopping.preference.put' => $this->shape(
                $payload,
                [
                    'minimumQuantity',
                    'alwaysKeep',
                    'neverSuggest',
                    'preferredPackId',
                    'leadTimeDays',
                    'targetCoverageDays',
                    'snoozeUntil',
                ],
                [
                    'minimumQuantity',
                    'alwaysKeep',
                    'neverSuggest',
                    'preferredPackId',
                    'leadTimeDays',
                    'targetCoverageDays',
                    'snoozeUntil',
                ],
                true,
                $baseRevision,
            ),
            'shopping.suggestion-feedback.create' => $this->shape(
                $payload,
                ['suggestionId', 'decision', 'resultQuantity', 'reason'],
                ['suggestionId', 'decision', 'resultQuantity', 'reason'],
                false,
                $baseRevision,
            ),
            'shopping.list.create' => $this->shape($payload, ['name', 'kind'], ['name', 'kind'], false, $baseRevision),
            'shopping.list.update' => $this->shape(
                $payload,
                ['name', 'status'],
                ['name', 'status'],
                true,
                $baseRevision,
            ),
            'shopping.list-line.update' => $this->shape(
                $payload,
                ['listId', 'description', 'quantity', 'archived'],
                ['listId', 'description', 'quantity', 'archived'],
                true,
                $baseRevision,
            ),
            'shopping.list-line.create' => $this->shape(
                $payload,
                ['listId', 'homeProductId', 'description', 'quantity', 'suggestionId'],
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

        if (in_array($type, ['inventory.location.update', 'purchasing.store.update'], true)) {
            if ($payload === [] || $baseRevision === null || $baseRevision < 1) {
                throw new Problem(
                    422,
                    'Invalid command',
                    'Metadata updates require a change and positive baseRevision.',
                );
            }
            foreach ($payload as $value) {
                if (!is_string($value)) {
                    throw new Problem(422, 'Invalid command', 'Metadata values must be strings.');
                }
            }
        }

        if (
            in_array(
                $type,
                [
                    'purchasing.receipt.update',
                    'purchasing.receipt.cancel',
                    'purchasing.receipt-line.update',
                    'purchasing.receipt-line.remove',
                ],
                true,
            ) &&
            ($baseRevision === null || $baseRevision < 1)
        ) {
            throw new Problem(422, 'Invalid draft command', 'Draft changes require a positive baseRevision.');
        }

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
        if (array_key_exists('unit', $payload) && ! in_array($payload['unit'], ['units', 'g', 'kg', 'ml', 'l'], true)) {
            throw new Problem(422, 'Invalid unit', 'Choose units, g, kg, ml or l.');
        }
        $uuidFields = match ($type) {
            'inventory.home-product.create' => ['productId', 'packId', 'homeCategoryId', 'globalCategoryId'],
            'inventory.home-product.update' => ['homeCategoryId', 'globalCategoryId'],
            'inventory.count-session.create' => ['locationId'],
            'inventory.count-line.upsert' => ['sessionId', 'homeProductId'],
            'inventory.count-line.remove' => ['sessionId'],
            'purchasing.receipt.create', 'purchasing.receipt.update' => ['storeId'],
            'purchasing.receipt-line.create', 'purchasing.receipt-line.update', 'purchasing.receipt-line.remove' => [
                'receiptId',
            ],
            'purchasing.receipt-line.approve' => ['receiptId', 'homeProductId'],
            'purchasing.receipt-line.unresolve' => ['receiptId'],
            'shopping.preference.put' => ['preferredPackId'],
            'shopping.list-line.create' => ['listId', 'homeProductId', 'suggestionId'],
            'shopping.suggestion-feedback.create' => ['suggestionId'],
            'shopping.list-line.checked', 'shopping.list-line.update' => ['listId'],
            default => [],
        };
        foreach ($uuidFields as $field) {
            $fieldValue = $payload[$field] ?? null;
            if ($fieldValue !== null) {
                $this->uuid($fieldValue, $field);
            }
        }

        $booleanFields = ['scopeComplete', 'checked', 'archived', 'alwaysKeep', 'neverSuggest'];
        foreach ($payload as $field => $fieldValue) {
            if (
                $type === 'shopping.suggestion-feedback.create' &&
                $field !== 'resultQuantity' &&
                $fieldValue === null
            ) {
                throw new Problem(422, 'Invalid command', $field . ' must not be null.');
            }
            if (in_array($type, ['shopping.list.update', 'shopping.list-line.update'], true) && $fieldValue === null) {
                throw new Problem(422, 'Invalid command', $field . ' must not be null.');
            }
            if (in_array($field, ['leadTimeDays', 'targetCoverageDays'], true)) {
                if (($field === 'leadTimeDays' || $fieldValue !== null) && !is_int($fieldValue)) {
                    throw new Problem(422, 'Invalid command', $field . ' must be an integer or null.');
                }
                continue;
            }
            if (in_array($field, $booleanFields, true) && !is_bool($fieldValue)) {
                throw new Problem(422, 'Invalid command', $field . ' must be boolean.');
            }
            if (!in_array($field, $uuidFields, true) && !in_array($field, $booleanFields, true)) {
                if ($fieldValue !== null && !is_string($fieldValue)) {
                    throw new Problem(422, 'Invalid command', $field . ' must be a string or null.');
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     */
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
