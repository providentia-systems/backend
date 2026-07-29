<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application\Health;

interface SyncMetricsProbe
{
    /** @return array{operations: int, accepted: int, conflicts: int, tombstones: int, changes: int, cursors: int} */
    public function metrics(): array;
}
