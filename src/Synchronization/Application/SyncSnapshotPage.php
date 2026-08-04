<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

final readonly class SyncSnapshotPage
{
    /** @param list<array<string, mixed>> $records */
    public function __construct(
        public int $highWater,
        public array $records,
        public bool $hasMore,
    ) {
        if ($this->highWater < 0) {
            throw new \InvalidArgumentException('A synchronization high-water mark cannot be negative.');
        }
        if ($this->hasMore && $this->records === []) {
            throw new \InvalidArgumentException('A continued snapshot page must contain a record.');
        }
    }
}
