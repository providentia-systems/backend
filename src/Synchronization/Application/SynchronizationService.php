<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Throwable;

/**
 * Coordinates generic protocol v1 reads/writes and typed pantry protocol v2 commands.
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
        private readonly ?SyncDeploymentInformation $deployment = null,
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
        $this->requireMember($identity, $homeId);
        $validatedEnvelope = $this->envelopes->validate(
            $identity->deviceId,
            $idempotencyKey,
            $envelope,
        );

        $permissions = $this->readPermissions($identity, $homeId);
        $scope = SyncReadPolicy::scope($identity, $permissions);
        $results = [];
        foreach ($validatedEnvelope->operations as $operation) {
            $result = $validatedEnvelope->protocolVersion === 1
                ? $this->processOperation($identity, $homeId, $operation, $scope)
                : $this->processCommand($identity, $homeId, $operation);
            $entityType = is_array($operation) && is_string($operation['entityType'] ?? null)
                ? $operation['entityType']
                : (is_array($operation) && is_string($operation['commandType'] ?? null)
                    ? SyncReadPolicy::entityForCommand($operation['commandType'])
                    : null);
            $results[] = SyncReadPolicy::result($result, $permissions, $entityType);
        }

        $highWater = $this->store->highWater($homeId);
        $this->requireUnchangedReadScope($identity, $homeId, $scope);

        return [
            'protocolVersion' => $validatedEnvelope->protocolVersion,
            'batchId' => $validatedEnvelope->batchId,
            'requestId' => $requestId,
            'serverTime' => $this->clock->now()->format(DATE_ATOM),
            'results' => $results,
            'highWaterCursor' => $this->cursors->encode($homeId, $highWater, $highWater, $scope),
        ];
    }

    /** @return array<string, mixed> */
    public function pull(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $requestId,
        ?string $cursor,
    ): array {
        $this->requireMember($identity, $homeId);
        if ($cursor === null || $cursor === '') {
            throw new Problem(
                410,
                'Synchronization bootstrap required',
                'Use the authorized bootstrap endpoint before the first incremental pull.',
                'https://providentia.invalid/problems/sync_resync_required',
            );
        }

        $permissions = $this->readPermissions($identity, $homeId);
        $scope = SyncReadPolicy::scope($identity, $permissions);
        $decoded = $this->cursors->decode($cursor, $homeId, $scope);
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
        // Advance over the scanned page, not merely its visible records.
        // An empty retained range is exhausted, including gaps after compaction.
        $position = $highWater;
        if ($changes !== []) {
            $position = (int) $changes[array_key_last($changes)]['cursor'];
        }
        $this->requireUnchangedReadScope($identity, $homeId, $scope);
        $this->acknowledge($identity, $homeId, $position);

        return [
            'protocolVersion' => 1,
            'requestId' => $requestId,
            'fromCursor' => $cursor,
            'pageCursor' => $this->cursors->encode($homeId, $position, $highWater, $scope),
            'highWaterCursor' => $this->cursors->encode($homeId, $highWater, $highWater, $scope),
            'hasMore' => $position < $highWater,
            'changes' => array_map(
                fn (array $change): array => $this->presenter->change($homeId, $highWater, $change, $scope),
                SyncReadPolicy::filter($changes, $permissions),
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
        $this->requireMember($identity, $homeId);
        $permissions = $this->readPermissions($identity, $homeId);
        $scope = SyncReadPolicy::scope($identity, $permissions);
        $limit = min($this->pageSize, max(1, $requestedLimit ?? $this->pageSize));
        $afterType = null;
        $afterId = null;
        if ($pageCursor === null || $pageCursor === '') {
            $highWater = $this->store->highWater($homeId);
        } else {
            $snapshotCursors = $this->snapshotCursors
                ?? throw new \LogicException('Snapshot cursor support is not configured.');
            $decoded = $snapshotCursors->decode($pageCursor, $homeId, $scope);
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
        $this->requireUnchangedReadScope($identity, $homeId, $scope);
        $incrementalCursor = null;
        $nextPageCursor = null;
        if ($snapshot->hasMore) {
            $lastKey = array_key_last($snapshot->records);
            if ($lastKey === null) {
                throw new \LogicException('A continuing synchronization snapshot must contain a record.');
            }
            $last = $snapshot->records[$lastKey];
            $snapshotCursors = $this->snapshotCursors
                ?? throw new \LogicException('Snapshot cursor support is not configured.');
            $nextPageCursor = $snapshotCursors->encode(
                $homeId,
                $snapshot->highWater,
                (string) $last['entityType'],
                (string) $last['entityId'],
                $scope,
            );
        } else {
            $incrementalCursor = $this->cursors->encode(
                $homeId,
                $snapshot->highWater,
                $snapshot->highWater,
                $scope,
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
                $scope,
            ),
            'hasMore' => $snapshot->hasMore,
            'records' => SyncReadPolicy::filter($snapshot->records, $permissions),
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
        $this->requireMember($identity, $homeId);
        if (! hash_equals($identity->deviceId, $deviceId)) {
            throw new Problem(
                403,
                'Device mismatch',
                'Operation receipts are bound to the authenticated device.',
                SyncProblemClassifier::DEVICE_MISMATCH,
            );
        }
        if ($operationIds === [] || count($operationIds) > 100) {
            throw new Problem(422, 'Invalid operation status request', 'Request between 1 and 100 operation IDs.');
        }
        $operationIds = array_values(array_unique(array_map(
            fn (mixed $id): string => $this->operationId($id),
            $operationIds,
        )));
        $permissions = $this->readPermissions($identity, $homeId);
        $scope = SyncReadPolicy::scope($identity, $permissions);
        $stored = $this->store->operationStatuses(
            $homeId,
            $identity->userId,
            $identity->deviceId,
            $operationIds,
        );
        $this->requireUnchangedReadScope($identity, $homeId, $scope);
        $operations = [];
        foreach ($operationIds as $operationId) {
            $operations[] = isset($stored[$operationId])
                ? [
                    'operationId' => $operationId,
                    'known' => true,
                    'result' => SyncReadPolicy::result($stored[$operationId], $permissions),
                ]
                : ['operationId' => $operationId, 'known' => false];
        }

        return ['protocolVersion' => 2, 'operations' => $operations];
    }

    /** @return array<string, mixed> */
    public function diagnostics(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->requireMember($identity, $homeId);
        $deployment = $this->deployment ?? throw new \LogicException('Deployment diagnostics are not configured.');
        $information = $deployment->information();
        $this->requireMember($identity, $homeId);

        return [
            'accountId' => $identity->userId,
            'deviceId' => $identity->deviceId,
            'homeId' => $homeId,
            'serverTime' => $this->clock->now()->format(DATE_ATOM),
            'deployment' => $information,
        ];
    }

    /**
     * Revalidate the original binding and execution evidence without replaying work.
     * Only device-binding failures are eligible; every other conflict stays blocked.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function revalidate(
        AuthenticatedIdentity $identity,
        string $homeId,
        array $request,
    ): array {
        $this->requireMember($identity, $homeId);
        if (array_diff(array_keys($request), ['accountId', 'deviceId', 'operations']) !== []) {
            throw new Problem(422, 'Invalid recovery request', 'The recovery request contains unknown fields.');
        }
        if (($request['accountId'] ?? null) !== $identity->userId) {
            throw new Problem(
                403,
                'Account mismatch',
                'Recovery requires the original authenticated account; unknown provenance needs explicit review.',
                SyncProblemClassifier::ACCOUNT_MISMATCH,
            );
        }
        if (($request['deviceId'] ?? null) !== $identity->deviceId) {
            throw new Problem(
                403,
                'Device mismatch',
                'Restore the original device session before revalidating saved operations.',
                SyncProblemClassifier::DEVICE_MISMATCH,
            );
        }
        $operations = $request['operations'] ?? null;
        if (
            ! is_array($operations) || ! array_is_list($operations)
            || count($operations) < 1 || count($operations) > 100
        ) {
            throw new Problem(422, 'Invalid recovery request', 'Request between 1 and 100 operations in queue order.');
        }
        $validator = $this->commands ?? throw new \LogicException('Command validation is not configured.');
        $hasher = $this->commandHasher ?? throw new \LogicException('Command hashing is not configured.');
        $validated = [];
        $seen = [];
        foreach ($operations as $operation) {
            if (
                ! is_array($operation)
                || array_diff(array_keys($operation), ['blockedCode', 'command']) !== []
                || ! is_string($operation['blockedCode'] ?? null)
                || ! is_array($operation['command'] ?? null)
            ) {
                throw new Problem(422, 'Invalid recovery request', 'Provide each original command and blockedCode.');
            }
            $command = $validator->validate($operation['command']);
            if (isset($seen[$command->operationId])) {
                throw new Problem(422, 'Invalid recovery request', 'Operation identifiers must be unique.');
            }
            $seen[$command->operationId] = true;
            $validated[] = ['command' => $command, 'blockedCode' => $operation['blockedCode']];
        }
        $permissions = $this->readPermissions($identity, $homeId);
        $scope = SyncReadPolicy::scope($identity, $permissions);
        $results = [];
        $predecessor = null;
        foreach ($validated as $operation) {
            $command = $operation['command'];
            $receipt = $this->store->operationReceipt($command->operationId);
            $result = ['operationId' => $command->operationId];
            if ($receipt !== null) {
                $matches = (string) $receipt['homeId'] === $homeId
                    && (string) $receipt['userId'] === $identity->userId
                    && (string) $receipt['deviceId'] === $identity->deviceId
                    && hash_equals((string) $receipt['requestHash'], $hasher->hash($command));
                if (! $matches) {
                    $result += ['action' => 'review_required', 'code' => 'immutable_request_mismatch'];
                } else {
                    /** @var array<string, mixed> $response */
                    $response = $receipt['response'];
                    $result += [
                        'action' => ($response['status'] ?? null) === 'accepted' ? 'readback' : 'review_required',
                        'code' => 'execution_receipt_found',
                        'result' => SyncReadPolicy::result($response, $permissions),
                    ];
                }
            } elseif ($predecessor !== null) {
                $result += [
                    'action' => 'await_predecessor', 'code' => 'dependency_pending', 'predecessorId' => $predecessor,
                ];
            } elseif ($operation['blockedCode'] !== 'device_binding_mismatch') {
                $result += ['action' => 'review_required', 'code' => 'block_not_eligible'];
            } elseif (! $this->canRetryCommand($identity, $homeId, $command)) {
                $result += ['action' => 'review_required', 'code' => 'permission_denied'];
            } else {
                $result += ['action' => 'retry_same_operation', 'code' => 'unknown_under_original_binding'];
            }
            if ($result['action'] !== 'readback') {
                $predecessor ??= $command->operationId;
            }
            $results[] = $result;
        }
        $this->requireUnchangedReadScope($identity, $homeId, $scope);

        return [
            'protocolVersion' => 2,
            'accountId' => $identity->userId,
            'deviceId' => $identity->deviceId,
            'homeId' => $homeId,
            'operations' => $results,
        ];
    }

    private function canRetryCommand(AuthenticatedIdentity $identity, string $homeId, SyncCommand $command): bool
    {
        $entity = SyncReadPolicy::entityForCommand($command->commandType);
        $permission = match (true) {
            $entity !== null && str_starts_with($entity, 'inventory-') => HomePermission::INVENTORY_WRITE,
            $entity !== null && str_starts_with($entity, 'purchasing-') => HomePermission::PURCHASES_WRITE,
            $entity !== null && str_starts_with($entity, 'shopping-') => HomePermission::SHOPPING_WRITE,
            default => null,
        };
        if ($permission === null) {
            return false;
        }
        try {
            $this->authorization->requirePermission($identity, $homeId, $permission);

            return true;
        } catch (Problem $problem) {
            if (! in_array($problem->status, [403, 404], true)) {
                throw $problem;
            }

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function processOperation(
        AuthenticatedIdentity $identity,
        string $homeId,
        mixed $operation,
        string $scope,
    ): array {
        if (! is_array($operation)) {
            return ['status' => 'validation_error', 'detail' => 'Operation must be an object.'];
        }

        try {
            $validated = $this->operations->validate($operation);
            $membership = $this->requireMember($identity, $homeId);
            if ((string) $membership['role'] === HomeAuthorization::VIEWER) {
                return [
                    'operationId' => $validated->operationId,
                    'status' => 'authorization_failure',
                    'code' => 'permission_denied',
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
                $scope,
            );
        } catch (Problem $problem) {
            return $this->problemResult((string) ($operation['operationId'] ?? ''), $problem);
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

                        return SyncReadPolicy::result(
                            $response,
                            $this->readPermissions($identity, $homeId),
                            SyncReadPolicy::entityForCommand($command->commandType),
                        );
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
            return $this->problemResult((string) ($operation['operationId'] ?? ''), $problem);
        } catch (Throwable) {
            return [
                'operationId' => (string) ($operation['operationId'] ?? ''),
                'status' => 'retryable_failure',
                'detail' => 'The command could not be processed safely.',
            ];
        }
    }

    /** @return array{operationId: string, status: string, code: string, detail: string} */
    private function problemResult(string $operationId, Problem $problem): array
    {
        return SyncProblemClassifier::result($operationId, $problem);
    }

    /** @return array<string, mixed> */
    private function requireMember(AuthenticatedIdentity $identity, string $homeId): array
    {
        try {
            return $this->authorization->requireMember($identity, $homeId);
        } catch (Problem $problem) {
            if ($problem->status !== 404) {
                throw $problem;
            }
            // Only this membership check establishes the home-access classification.
            // The public 404 remains non-enumerating and carries no resource details.
            throw new Problem(
                404,
                'Not found',
                'The requested resource is unavailable.',
                SyncProblemClassifier::HOME_ACCESS_DENIED,
            );
        }
    }

    /** A concurrent revocation must not publish or acknowledge an old read scope. */
    private function requireUnchangedReadScope(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $scope,
    ): void {
        $this->requireMember($identity, $homeId);
        $current = SyncReadPolicy::scope($identity, $this->readPermissions($identity, $homeId));
        if (! hash_equals($scope, $current)) {
            throw new Problem(
                410,
                'Synchronization scope changed',
                'Bootstrap the currently authorized data without discarding pending operations.',
                'https://providentia.invalid/problems/sync_resync_required',
            );
        }
    }

    /** @return list<string> */
    private function readPermissions(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->requireMember($identity, $homeId);
        $permissions = [];
        foreach (array_unique(SyncReadPolicy::ENTITY_PERMISSIONS) as $permission) {
            try {
                $this->authorization->requirePermission($identity, $homeId, $permission);
                $permissions[] = $permission;
            } catch (Problem $problem) {
                if ($problem->status !== 404) {
                    throw $problem;
                }
            }
        }

        return $permissions;
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
