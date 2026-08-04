<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\Home\Application\HomeAuthorization;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Throwable;

/**
 * Coordinates the version-one push, pull, and bootstrap use cases.
 *
 * Validation, per-entity policy, request hashing, persistence, and public
 * response mapping are delegated to focused collaborators.
 */
final class SynchronizationService
{
    public function __construct(
        private readonly SyncStore $store,
        private readonly CursorCodec $cursors,
        private readonly HomeAuthorization $authorization,
        private readonly Clock $clock,
        private readonly SyncEnvelopeValidator $envelopes,
        private readonly SyncOperationValidator $operations,
        private readonly SyncRequestHasher $hasher,
        private readonly SyncResultPresenter $presenter,
        private readonly int $pageSize,
        private readonly ?SyncCommandValidator $commands = null,
        private readonly ?SyncCommandDispatcher $commandDispatcher = null,
        private readonly ?SyncCommandHasher $commandHasher = null,
        private readonly ?TransactionManager $transactions = null,
        private readonly ?SnapshotCursorCodec $snapshotCursors = null,
    ) {
        if ($this->pageSize < 1) {
            throw new \InvalidArgumentException('pageSize must be positive.');
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    public function push(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $requestId,
        string $idempotencyKey,
        array $envelope,
    ): array {
        $this->authorization->requireMember($identity, $homeId);
        $validatedEnvelope = $this->envelopes->validate(
            $identity->deviceId,
            $idempotencyKey,
            $envelope,
        );

        $results = [];
        foreach ($validatedEnvelope->operations as $operation) {
            $results[] = $validatedEnvelope->protocolVersion === 1
                ? $this->processOperation($identity, $homeId, $operation)
                : $this->processCommand($identity, $homeId, $operation);
        }

        $highWater = $this->store->highWater($homeId);

        return [
            'protocolVersion' => $validatedEnvelope->protocolVersion,
            'batchId' => $validatedEnvelope->batchId,
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
            throw new Problem(
                410,
                'Synchronization bootstrap required',
                'Use the authorized bootstrap endpoint before the first incremental pull.',
                'https://providentia.invalid/problems/sync_resync_required',
            );
        }

        $decoded = $this->cursors->decode($cursor, $homeId);
        $after = $decoded['position'];
        $highWater = $decoded['highWater'];
        if ($after < $this->store->minimumAvailableCursor($homeId)) {
            throw new Problem(
                410,
                'Synchronization bootstrap required',
                'The requested cursor predates retained synchronization history.',
                'https://providentia.invalid/problems/sync_resync_required',
            );
        }
        if ($after === $highWater) {
            $highWater = max($after, $this->store->highWater($homeId));
        }

        $changes = $this->store->changes($homeId, $after, $highWater, $this->pageSize);
        $position = $after;
        if ($changes !== []) {
            $position = (int) $changes[array_key_last($changes)]['cursor'];
        }
        $this->acknowledge($identity, $homeId, $position);

        return [
            'protocolVersion' => 1,
            'requestId' => $requestId,
            'fromCursor' => $cursor,
            'pageCursor' => $this->cursors->encode($homeId, $position, $highWater),
            'highWaterCursor' => $this->cursors->encode($homeId, $highWater, $highWater),
            'hasMore' => $position < $highWater,
            'changes' => array_map(
                fn (array $change): array => $this->presenter->change($homeId, $highWater, $change),
                $changes,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function bootstrap(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $requestId,
        ?string $pageCursor = null,
        ?int $requestedLimit = null,
    ): array {
        $this->authorization->requireMember($identity, $homeId);
        $limit = min($this->pageSize, max(1, $requestedLimit ?? $this->pageSize));
        $afterType = null;
        $afterId = null;
        if ($pageCursor === null || $pageCursor === '') {
            $highWater = $this->store->highWater($homeId);
        } else {
            $snapshotCursors = $this->snapshotCursors
                ?? throw new \LogicException('Snapshot cursor support is not configured.');
            $decoded = $snapshotCursors->decode($pageCursor, $homeId);
            $highWater = $decoded['highWater'];
            $afterType = $decoded['entityType'];
            $afterId = $decoded['entityId'];
        }
        $snapshot = $this->store->captureSnapshotPage(
            $homeId,
            $highWater,
            $afterType,
            $afterId,
            $limit,
        );
        $incrementalCursor = null;
        $nextPageCursor = null;
        if ($snapshot->hasMore) {
            $last = $snapshot->records[array_key_last($snapshot->records)];
            $snapshotCursors = $this->snapshotCursors
                ?? throw new \LogicException('Snapshot cursor support is not configured.');
            $nextPageCursor = $snapshotCursors->encode(
                $homeId,
                $snapshot->highWater,
                (string) $last['entityType'],
                (string) $last['entityId'],
            );
        } else {
            $incrementalCursor = $this->cursors->encode(
                $homeId,
                $snapshot->highWater,
                $snapshot->highWater,
            );
            $this->acknowledge($identity, $homeId, $snapshot->highWater);
        }

        return [
            'protocolVersion' => 1,
            'requestId' => $requestId,
            'snapshotCursor' => $incrementalCursor,
            'pageCursor' => $nextPageCursor,
            'highWaterCursor' => $this->cursors->encode(
                $homeId,
                $snapshot->highWater,
                $snapshot->highWater,
            ),
            'hasMore' => $snapshot->hasMore,
            'records' => $snapshot->records,
        ];
    }

    /**
     * @param list<string> $operationIds
     * @return array{protocolVersion: int, operations: list<array<string, mixed>>}
     */
    public function operationStatuses(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $deviceId,
        array $operationIds,
    ): array {
        $this->authorization->requireMember($identity, $homeId);
        if (! hash_equals($identity->deviceId, $deviceId)) {
            throw new Problem(403, 'Device mismatch', 'Operation receipts are bound to the authenticated device.');
        }
        if ($operationIds === [] || count($operationIds) > 100) {
            throw new Problem(422, 'Invalid operation status request', 'Request between 1 and 100 operation IDs.');
        }
        $operationIds = array_values(array_unique(array_map(
            fn (mixed $id): string => $this->operationId($id),
            $operationIds,
        )));
        $stored = $this->store->operationStatuses(
            $homeId,
            $identity->userId,
            $identity->deviceId,
            $operationIds,
        );
        $operations = [];
        foreach ($operationIds as $operationId) {
            $operations[] = isset($stored[$operationId])
                ? ['operationId' => $operationId, 'known' => true, 'result' => $stored[$operationId]]
                : ['operationId' => $operationId, 'known' => false];
        }

        return ['protocolVersion' => 2, 'operations' => $operations];
    }

    /** @return array<string, mixed> */
    private function processOperation(
        AuthenticatedIdentity $identity,
        string $homeId,
        mixed $operation,
    ): array {
        if (! is_array($operation)) {
            return ['status' => 'validation_error', 'detail' => 'Operation must be an object.'];
        }

        try {
            $validated = $this->operations->validate($operation);
            $membership = $this->authorization->requireMember($identity, $homeId);
            if ((string) $membership['role'] === HomeAuthorization::VIEWER) {
                return [
                    'operationId' => $validated->operationId,
                    'status' => 'authorization_failure',
                    'detail' => 'The current home role is read-only.',
                ];
            }

            return $this->presenter->applied(
                $homeId,
                $this->store->apply(
                    $homeId,
                    $identity->userId,
                    $identity->deviceId,
                    $validated,
                    $this->hasher->hash($validated),
                    $this->clock->now(),
                ),
            );
        } catch (Problem $problem) {
            return [
                'operationId' => (string) ($operation['operationId'] ?? ''),
                'status' => in_array($problem->status, [403, 404], true)
                    ? 'authorization_failure'
                    : 'validation_error',
                'detail' => $problem->getMessage(),
            ];
        } catch (Throwable) {
            return [
                'operationId' => (string) ($operation['operationId'] ?? ''),
                'status' => 'retryable_failure',
                'detail' => 'The operation could not be processed safely.',
            ];
        }
    }

    /** @return array<string, mixed> */
    private function processCommand(
        AuthenticatedIdentity $identity,
        string $homeId,
        mixed $operation,
    ): array {
        if (! is_array($operation)) {
            return ['status' => 'validation_error', 'detail' => 'Command must be an object.'];
        }
        try {
            $commands = $this->commands ?? throw new \LogicException('Command validation is not configured.');
            $dispatcher = $this->commandDispatcher
                ?? throw new \LogicException('Command dispatch is not configured.');
            $hasher = $this->commandHasher ?? throw new \LogicException('Command hashing is not configured.');
            $transactions = $this->transactions
                ?? throw new \LogicException('Command transactions are not configured.');
            $command = $commands->validate($operation);
            $requestHash = $hasher->hash($command);

            return $transactions->transactional(function () use (
                $identity,
                $homeId,
                $command,
                $dispatcher,
                $requestHash,
            ): array {
                $receipt = $this->store->operationReceipt($command->operationId);
                if ($receipt !== null) {
                    if (
                        (string) $receipt['homeId'] === $homeId
                        && (string) $receipt['userId'] === $identity->userId
                        && (string) $receipt['deviceId'] === $identity->deviceId
                        && hash_equals((string) $receipt['requestHash'], $requestHash)
                    ) {
                        /** @var array<string, mixed> $response */
                        $response = $receipt['response'];

                        return $response;
                    }

                    return [
                        'operationId' => $command->operationId,
                        'status' => 'conflict',
                        'code' => 'operation_id_reuse',
                        'detail' => 'The operation identifier is bound to another immutable request.',
                    ];
                }
                $result = $dispatcher->dispatch($identity, $homeId, $command);
                $response = [
                    'operationId' => $command->operationId,
                    'status' => 'accepted',
                    'commandType' => $command->commandType,
                    'entityId' => $command->entityId,
                    'result' => $result,
                ];
                $this->store->recordCommandReceipt(
                    $homeId,
                    $identity->userId,
                    $identity->deviceId,
                    $command,
                    $requestHash,
                    $response,
                    $this->clock->now(),
                );

                return $response;
            });
        } catch (Problem $problem) {
            return [
                'operationId' => (string) ($operation['operationId'] ?? ''),
                'status' => match (true) {
                    in_array($problem->status, [403, 404], true) => 'authorization_failure',
                    $problem->status === 409 => 'conflict',
                    default => 'validation_error',
                },
                'detail' => $problem->getMessage(),
            ];
        } catch (Throwable) {
            return [
                'operationId' => (string) ($operation['operationId'] ?? ''),
                'status' => 'retryable_failure',
                'detail' => 'The command could not be processed safely.',
            ];
        }
    }

    private function operationId(mixed $value): string
    {
        if (
            ! is_string($value)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1
        ) {
            throw new Problem(422, 'Invalid identifier', 'operationId must be a UUID.');
        }

        return strtolower($value);
    }

    private function acknowledge(
        AuthenticatedIdentity $identity,
        string $homeId,
        int $position,
    ): void {
        $this->store->acknowledgeCursor(
            $homeId,
            $identity->userId,
            $identity->deviceId,
            $position,
            $this->clock->now(),
        );
    }
}
