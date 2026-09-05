<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use DateTimeImmutable;

interface SyncStore
{
    /**
     * @return array<string, mixed> */
    public function apply(
        string $homeId,
        string $userId,
        string $deviceId,
        SyncOperation $operation,
        string $requestHash,
        DateTimeImmutable $at,
    ): array;

    public function highWater(string $homeId): int;

    public function captureSnapshot(string $homeId, int $limit): SyncSnapshot;

    public function captureSnapshotPage(
        string $homeId,
        int $highWater,
        ?string $afterEntityType,
        ?string $afterEntityId,
        int $limit,
    ): SyncSnapshotPage;

    /**
     * @return list<array<string, mixed>> */
    public function changes(string $homeId, int $after, int $highWater, int $limit): array;

    public function acknowledgeCursor(
        string $homeId,
        string $userId,
        string $deviceId,
        int $position,
        DateTimeImmutable $at,
    ): void;

    /**
     * @return array<string, mixed>|null */
    public function operationReceipt(string $operationId): ?array;

    /**
     * @param array<string, mixed> $response */
    public function recordCommandReceipt(
        string $homeId,
        string $userId,
        string $deviceId,
        SyncCommand $command,
        string $requestHash,
        array $response,
        DateTimeImmutable $at,
    ): void;

    /**
     *
     * @param list<string> $operationIds
     *
     * @return array<string, array<string, mixed>>
     */
    public function operationStatuses(
        string $homeId,
        string $userId,
        string $deviceId,
        array $operationIds,
    ): array;

    /**
     * @return array{deleted: int, safeCursor: int} */
    public function compactTombstones(string $homeId, DateTimeImmutable $at, int $batchSize): array;

    public function minimumAvailableCursor(string $homeId): int;

    /**
     * @return list<string> */
    public function homesWithExpiredTombstones(DateTimeImmutable $at, int $limit): array;

    /**
     * @return array{operations: int, accepted: int, conflicts: int, tombstones: int, changes: int, cursors: int} */
    public function metrics(): array;
}
