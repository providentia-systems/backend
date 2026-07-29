<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\Home\Application\HomeAuthorization;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Http\HttpProblem;

final class SynchronizationService
{
    public function __construct(
        private readonly SyncStore $store,
        private readonly CursorCodec $cursors,
        private readonly HomeAuthorization $authorization,
        private readonly Clock $clock,
        private readonly int $maxBatchOperations,
        private readonly int $maxPayloadBytes,
        private readonly int $pageSize,
    ) {
    }

    /** @param array<string, mixed> $envelope @return array<string, mixed> */
    public function push(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $requestId,
        string $idempotencyKey,
        array $envelope,
    ): array {
        $this->authorization->requireMember($identity, $homeId);
        $this->rejectUnknownKeys(
            $envelope,
            ['protocolVersion', 'batchId', 'deviceId', 'lastPulledCursor', 'operations'],
            'synchronization envelope',
        );
        if (($envelope['protocolVersion'] ?? null) !== 1) {
            throw new HttpProblem(422, 'Unsupported protocol', 'protocolVersion must be integer 1.');
        }
        if (($envelope['deviceId'] ?? null) !== $identity->deviceId) {
            throw new HttpProblem(403, 'Device mismatch', 'The synchronization device does not match the session.');
        }
        $batchId = $this->uuid((string) ($envelope['batchId'] ?? ''), 'batchId');
        if (! hash_equals($batchId, strtolower(trim($idempotencyKey)))) {
            throw new HttpProblem(
                422,
                'Invalid idempotency key',
                'Idempotency-Key must equal batchId so an identical batch retry has one stable identity.',
            );
        }
        if (
            isset($envelope['lastPulledCursor'])
            && $envelope['lastPulledCursor'] !== null
            && ! is_string($envelope['lastPulledCursor'])
        ) {
            throw new HttpProblem(422, 'Invalid cursor', 'lastPulledCursor must be an opaque string or null.');
        }
        $operations = $envelope['operations'] ?? null;
        if (
            ! is_array($operations)
            || ! array_is_list($operations)
            || count($operations) < 1
            || count($operations) > $this->maxBatchOperations
        ) {
            throw new HttpProblem(422, 'Invalid batch', 'The batch operation count is outside the supported bounds.');
        }

        $results = [];
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                $results[] = ['status' => 'validation_error', 'detail' => 'Operation must be an object.'];
                continue;
            }
            try {
                $validated = $this->validateOperation($operation);
                // Re-evaluate the current role for every operation, not only once per batch.
                $membership = $this->authorization->requireMember($identity, $homeId);
                if ((string) $membership['role'] === HomeAuthorization::VIEWER) {
                    $results[] = [
                        'operationId' => $validated['operationId'],
                        'status' => 'authorization_failure',
                        'detail' => 'The current home role is read-only.',
                    ];
                    continue;
                }
                $result = $this->store->apply(
                    $homeId,
                    $identity->userId,
                    $identity->deviceId,
                    $validated,
                    hash('sha256', $this->canonicalJson($validated)),
                    $this->clock->now(),
                );
                if (($result['status'] ?? null) === 'accepted') {
                    $position = (int) $result['cursor'];
                    $result['revision'] = $result['serverRevision'];
                    $result['changeCursor'] = $this->cursors->encode($homeId, $position, $position);
                    $result['representation'] = $result['deleted']
                        ? ['id' => $result['entityId'], 'revision' => $result['revision'], 'deleted' => true]
                        : array_merge(
                            ['id' => $result['entityId'], 'revision' => $result['revision']],
                            (array) $result['payload'],
                        );
                    unset($result['cursor'], $result['serverRevision'], $result['payload'], $result['deleted']);
                }
                $results[] = $result;
            } catch (HttpProblem $problem) {
                $results[] = [
                    'operationId' => (string) ($operation['operationId'] ?? ''),
                    'status' => in_array($problem->status, [403, 404], true)
                        ? 'authorization_failure'
                        : 'validation_error',
                    'detail' => $problem->getMessage(),
                ];
            } catch (\Throwable) {
                $results[] = [
                    'operationId' => (string) ($operation['operationId'] ?? ''),
                    'status' => 'retryable_failure',
                    'detail' => 'The operation could not be processed safely.',
                ];
            }
        }
        $highWater = $this->store->highWater($homeId);

        return [
            'protocolVersion' => 1,
            'batchId' => $batchId,
            'requestId' => $requestId,
            'serverTime' => $this->clock->now()->format(DATE_ATOM),
            'results' => $results,
            'highWaterCursor' => $this->cursors->encode($homeId, $highWater, $highWater),
        ];
    }

    /** @return array<string, mixed> */
    public function pull(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $requestId,
        ?string $cursor,
    ): array {
        $this->authorization->requireMember($identity, $homeId);
        if ($cursor === null || $cursor === '') {
            throw new HttpProblem(
                410,
                'Synchronization bootstrap required',
                'Use the authorized bootstrap endpoint before the first incremental pull.',
                'https://providentia.invalid/problems/sync_resync_required',
            );
        }
        $decoded = $this->cursors->decode($cursor, $homeId);
        $after = $decoded['position'];
        $highWater = $decoded['highWater'];
        if ($after === $highWater) {
            // The previous frozen window is complete. Capture one new boundary so
            // later server commits become visible without moving it mid-page.
            $highWater = max($after, $this->store->highWater($homeId));
        }
        $fromCursor = $cursor;
        $changes = $this->store->changes($homeId, $after, $highWater, $this->pageSize);
        $position = $after;
        if ($changes !== []) {
            $position = (int) $changes[array_key_last($changes)]['cursor'];
        }
        $hasMore = $position < $highWater;
        $pageCursor = $this->cursors->encode($homeId, $position, $highWater);
        $this->store->acknowledgeCursor(
            $homeId,
            $identity->userId,
            $identity->deviceId,
            $position,
            $this->clock->now(),
        );

        $publicChanges = array_map(function (array $change) use ($homeId, $highWater): array {
            $position = (int) $change['cursor'];
            $representation = $change['operationType'] === 'delete'
                ? null
                : array_merge(
                    ['id' => $change['entityId'], 'revision' => $change['revision']],
                    (array) $change['payload'],
                );

            return [
                'cursor' => $this->cursors->encode($homeId, $position, $highWater),
                'entityType' => $change['entityType'],
                'entityId' => $change['entityId'],
                'operation' => $change['operationType'] === 'delete' ? 'delete' : 'upsert',
                'revision' => $change['revision'],
                'serverTimestamp' => $change['changedAt'],
                'representationSchemaVersion' => $change['payloadSchemaVersion'],
                ...($representation === null
                    ? ['tombstone' => ['deletedAt' => $change['changedAt']]]
                    : ['representation' => $representation]),
            ];
        }, $changes);

        return [
            'protocolVersion' => 1,
            'requestId' => $requestId,
            'fromCursor' => $fromCursor,
            'pageCursor' => $pageCursor,
            'highWaterCursor' => $this->cursors->encode($homeId, $highWater, $highWater),
            'hasMore' => $hasMore,
            'changes' => $publicChanges,
        ];
    }

    /** @return array<string, mixed> */
    public function bootstrap(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $requestId,
    ): array {
        $this->authorization->requireMember($identity, $homeId);
        $highWater = $this->store->highWater($homeId);
        $records = $this->store->snapshot($homeId, $this->pageSize + 1);
        if (count($records) > $this->pageSize) {
            throw new HttpProblem(
                409,
                'Paged bootstrap required',
                'The snapshot exceeds this prototype page boundary; use the documented paged bootstrap upgrade.',
            );
        }
        $cursor = $this->cursors->encode($homeId, $highWater, $highWater);
        $this->store->acknowledgeCursor(
            $homeId,
            $identity->userId,
            $identity->deviceId,
            $highWater,
            $this->clock->now(),
        );

        return [
            'protocolVersion' => 1,
            'requestId' => $requestId,
            'snapshotCursor' => $cursor,
            'records' => $records,
        ];
    }

    /** @param array<string, mixed> $operation @return array<string, mixed> */
    private function validateOperation(array $operation): array
    {
        $this->rejectUnknownKeys(
            $operation,
            [
                'operationId',
                'entityType',
                'entityId',
                'operationType',
                'baseRevision',
                'clientTimestamp',
                'payloadSchemaVersion',
                'payload',
            ],
            'synchronization operation',
        );
        foreach (
            [
                'operationId',
                'entityType',
                'entityId',
                'operationType',
                'clientTimestamp',
                'payloadSchemaVersion',
                'payload',
            ] as $field
        ) {
            if (! array_key_exists($field, $operation)) {
                throw new HttpProblem(422, 'Invalid operation', 'Missing operation field: ' . $field);
            }
        }
        $operationId = $this->uuid((string) $operation['operationId'], 'operationId');
        $entityId = $this->uuid((string) $operation['entityId'], 'entityId');
        if (! in_array($operation['entityType'], ['home-preference', 'private-note'], true)) {
            throw new HttpProblem(422, 'Invalid operation', 'entityType is not enabled for synchronization.');
        }
        if (! in_array($operation['operationType'], ['put', 'delete'], true)) {
            throw new HttpProblem(422, 'Invalid operation', 'operationType must be put or delete.');
        }
        $baseRevision = $operation['baseRevision'] ?? null;
        if (
            ($baseRevision !== null && (! is_int($baseRevision) || $baseRevision < 0))
            || (int) $operation['payloadSchemaVersion'] !== 1
        ) {
            throw new HttpProblem(422, 'Invalid operation', 'Revision or payload schema version is invalid.');
        }
        if (
            ! is_array($operation['payload'])
            || ($operation['payload'] !== [] && array_is_list($operation['payload']))
        ) {
            throw new HttpProblem(422, 'Invalid operation', 'payload must be an object.');
        }
        if ($operation['operationType'] === 'delete' && $operation['payload'] !== []) {
            throw new HttpProblem(422, 'Invalid operation', 'A delete operation payload must be empty.');
        }
        if (count($operation['payload']) > 128) {
            throw new HttpProblem(422, 'Invalid operation', 'The operation payload has too many properties.');
        }
        $this->validateEntityPayload(
            (string) $operation['entityType'],
            (string) $operation['operationType'],
            $operation['payload'],
        );
        if (strlen(json_encode($operation['payload'], JSON_THROW_ON_ERROR)) > $this->maxPayloadBytes) {
            throw new HttpProblem(422, 'Invalid operation', 'The operation payload is too large.');
        }
        try {
            if (
                preg_match(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                    (string) $operation['clientTimestamp'],
                ) !== 1
            ) {
                throw new \InvalidArgumentException('Timestamp shape is not RFC 3339.');
            }
            new \DateTimeImmutable((string) $operation['clientTimestamp']);
        } catch (\Throwable) {
            throw new HttpProblem(422, 'Invalid operation', 'clientTimestamp must be an RFC 3339 timestamp.');
        }

        return [
            'operationId' => $operationId,
            'entityType' => (string) $operation['entityType'],
            'entityId' => $entityId,
            'operationType' => (string) $operation['operationType'],
            'baseRevision' => $baseRevision,
            'clientTimestamp' => (string) $operation['clientTimestamp'],
            'payloadSchemaVersion' => 1,
            'payload' => $operation['payload'],
        ];
    }

    private function uuid(string $value, string $field): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new HttpProblem(422, 'Invalid identifier', $field . ' must be a UUID.');
        }

        return strtolower($value);
    }

    /** @param array<string, mixed> $payload */
    private function validateEntityPayload(string $entityType, string $operationType, array $payload): void
    {
        if ($operationType === 'delete') {
            return;
        }
        $allowed = $entityType === 'private-note'
            ? ['title', 'body']
            : ['defaultLocale', 'defaultCurrency', 'defaultTimezone', 'measurementSystem'];
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) {
            throw new HttpProblem(
                422,
                'Invalid operation',
                'The payload contains unknown or server-owned fields.',
            );
        }
        if ($entityType === 'private-note') {
            $body = $payload['body'] ?? null;
            $title = $payload['title'] ?? null;
            if (! is_string($body) || mb_strlen($body) < 1 || mb_strlen($body) > 4000) {
                throw new HttpProblem(
                    422,
                    'Invalid operation',
                    'private-note.body must contain 1 to 4000 characters.',
                );
            }
            if (
                array_key_exists('title', $payload)
                && (! is_string($title) || mb_strlen($title) > 120)
            ) {
                throw new HttpProblem(
                    422,
                    'Invalid operation',
                    'private-note.title must contain at most 120 characters.',
                );
            }

            return;
        }
        if ($payload === []) {
            throw new HttpProblem(422, 'Invalid operation', 'home-preference requires at least one field.');
        }
        if (
            array_key_exists('defaultLocale', $payload)
            && (
                ! is_string($payload['defaultLocale'])
                || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $payload['defaultLocale']) !== 1
            )
        ) {
            throw new HttpProblem(422, 'Invalid operation', 'defaultLocale is invalid.');
        }
        if (
            array_key_exists('defaultCurrency', $payload)
            && (
                ! is_string($payload['defaultCurrency'])
                || preg_match('/^[A-Z]{3}$/', $payload['defaultCurrency']) !== 1
            )
        ) {
            throw new HttpProblem(422, 'Invalid operation', 'defaultCurrency is invalid.');
        }
        if (array_key_exists('defaultTimezone', $payload)) {
            if (! is_string($payload['defaultTimezone']) || mb_strlen($payload['defaultTimezone']) > 64) {
                throw new HttpProblem(422, 'Invalid operation', 'defaultTimezone is invalid.');
            }
            try {
                new \DateTimeZone($payload['defaultTimezone']);
            } catch (\Throwable) {
                throw new HttpProblem(422, 'Invalid operation', 'defaultTimezone is invalid.');
            }
        }
        if (
            array_key_exists('measurementSystem', $payload)
            && (
                ! is_string($payload['measurementSystem'])
                || ! in_array($payload['measurementSystem'], ['metric', 'imperial'], true)
            )
        ) {
            throw new HttpProblem(422, 'Invalid operation', 'measurementSystem is invalid.');
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     */
    private function rejectUnknownKeys(array $value, array $allowed, string $objectName): void
    {
        $unknown = array_values(array_diff(array_keys($value), $allowed));
        if ($unknown === []) {
            return;
        }
        sort($unknown);

        throw new HttpProblem(
            422,
            'Invalid request',
            sprintf('%s contains unknown fields: %s.', ucfirst($objectName), implode(', ', $unknown)),
        );
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item);

            return array_map($normalize, $item);
        };

        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
