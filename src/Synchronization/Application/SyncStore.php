<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use DateTimeImmutable;

interface SyncStore
{
    /** @return array<string, mixed> */
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

    /** @return list<array<string, mixed>> */
    public function changes(string $homeId, int $after, int $highWater, int $limit): array;

    public function acknowledgeCursor(
        string $homeId,
        string $userId,
        string $deviceId,
        int $position,
        DateTimeImmutable $at,
    ): void;

    /** @return array{operations: int, accepted: int, conflicts: int, tombstones: int, changes: int, cursors: int} */
    public function metrics(): array;
}
