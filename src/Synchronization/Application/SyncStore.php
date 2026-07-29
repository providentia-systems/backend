<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use DateTimeImmutable;

interface SyncStore
{
    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public function apply(
        string $homeId,
        string $userId,
        string $deviceId,
        array $operation,
        string $requestHash,
        DateTimeImmutable $at,
    ): array;

    public function highWater(string $homeId): int;

    /** @return list<array<string, mixed>> */
    public function snapshot(string $homeId, int $limit): array;

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
