<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\Home\Application\HomeAuthorization;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
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
            $results[] = $this->processOperation($identity, $homeId, $operation);
        }

        $highWater = $this->store->highWater($homeId);

        return [
            'protocolVersion' => 1,
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
    ): array {
        $this->authorization->requireMember($identity, $homeId);
        $snapshot = $this->store->captureSnapshot($homeId, $this->pageSize + 1);
        if (count($snapshot->records) > $this->pageSize) {
            throw new Problem(
                409,
                'Paged bootstrap required',
                'The snapshot exceeds this prototype page boundary; use the documented paged bootstrap upgrade.',
            );
        }

        $cursor = $this->cursors->encode($homeId, $snapshot->highWater, $snapshot->highWater);
        $this->acknowledge($identity, $homeId, $snapshot->highWater);

        return [
            'protocolVersion' => 1,
            'requestId' => $requestId,
            'snapshotCursor' => $cursor,
            'records' => $snapshot->records,
        ];
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
