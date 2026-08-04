<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

interface SyncBackfillStore
{
    /** @return list<SyncBackfillRecord> */
    public function missingRecords(?string $homeId, int $limit): array;

    public function hasChange(string $homeId, string $entityType, string $entityId): bool;

    public function fallbackActor(string $homeId): string;
}
