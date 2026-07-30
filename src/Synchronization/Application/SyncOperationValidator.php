<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Providentia\SharedKernel\Application\Problem;
use Throwable;

final class SyncOperationValidator
{
    private const FIELDS = [
        'operationId',
        'entityType',
        'entityId',
        'operationType',
        'baseRevision',
        'clientTimestamp',
        'payloadSchemaVersion',
        'payload',
    ];

    private const REQUIRED_FIELDS = [
        'operationId',
        'entityType',
        'entityId',
        'operationType',
        'clientTimestamp',
        'payloadSchemaVersion',
        'payload',
    ];

    public function __construct(
        private readonly SyncEntityPolicyRegistry $policies,
        private readonly int $maxPayloadBytes,
    ) {
        if ($this->maxPayloadBytes < 2) {
            throw new InvalidArgumentException('maxPayloadBytes must allow a JSON object.');
        }
    }

    /** @param array<string, mixed> $operation */
    public function validate(array $operation): SyncOperation
    {
        $this->rejectUnknownKeys($operation);
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $operation)) {
                throw new Problem(422, 'Invalid operation', 'Missing operation field: ' . $field);
            }
        }

        $operationId = $this->uuid((string) $operation['operationId'], 'operationId');
        $entityId = $this->uuid((string) $operation['entityId'], 'entityId');
        $entityType = $operation['entityType'];
        if (! is_string($entityType)) {
            throw new Problem(422, 'Invalid operation', 'entityType must be a string.');
        }
        $policy = $this->policies->policyFor($entityType);

        $operationType = $operation['operationType'];
        if (! is_string($operationType) || ! in_array($operationType, ['put', 'delete'], true)) {
            throw new Problem(422, 'Invalid operation', 'operationType must be put or delete.');
        }

        $baseRevision = $operation['baseRevision'] ?? null;
        if ($baseRevision !== null && (! is_int($baseRevision) || $baseRevision < 0)) {
            throw new Problem(422, 'Invalid operation', 'baseRevision must be a non-negative integer or null.');
        }
        if ($operation['payloadSchemaVersion'] !== 1) {
            throw new Problem(422, 'Invalid operation', 'payloadSchemaVersion must be integer 1.');
        }

        $payload = $operation['payload'];
        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new Problem(422, 'Invalid operation', 'payload must be an object.');
        }
        if ($operationType === 'delete' && $payload !== []) {
            throw new Problem(422, 'Invalid operation', 'A delete operation payload must be empty.');
        }
        if (count($payload) > 128) {
            throw new Problem(422, 'Invalid operation', 'The operation payload has too many properties.');
        }
        if ($operationType === 'put') {
            $policy->validatePut($payload);
        }
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > $this->maxPayloadBytes) {
            throw new Problem(422, 'Invalid operation', 'The operation payload is too large.');
        }

        $clientTimestamp = $this->validateTimestamp($operation['clientTimestamp']);

        return new SyncOperation(
            $operationId,
            $entityType,
            $entityId,
            $operationType,
            $baseRevision,
            $clientTimestamp,
            1,
            $payload,
        );
    }

    /** @param array<string, mixed> $operation */
    private function rejectUnknownKeys(array $operation): void
    {
        $unknown = array_values(array_diff(array_keys($operation), self::FIELDS));
        if ($unknown === []) {
            return;
        }
        sort($unknown);

        throw new Problem(
            422,
            'Invalid request',
            sprintf(
                'Synchronization operation contains unknown fields: %s.',
                implode(', ', $unknown),
            ),
        );
    }

    private function uuid(string $value, string $field): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new Problem(422, 'Invalid identifier', $field . ' must be a UUID.');
        }

        return strtolower($value);
    }

    private function validateTimestamp(mixed $value): string
    {
        if (
            ! is_string($value)
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                $value,
            ) !== 1
        ) {
            throw new Problem(
                422,
                'Invalid operation',
                'clientTimestamp must be an RFC 3339 timestamp.',
            );
        }
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new Problem(
                422,
                'Invalid operation',
                'clientTimestamp must be an RFC 3339 timestamp.',
            );
        }

        return $value;
    }
}
