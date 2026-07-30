<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

final readonly class SyncSnapshot
{
    /**
     * @param list<array<string, mixed>> $records
     */
    public function __construct(
        public int $highWater,
        public array $records,
    ) {
        if ($this->highWater < 0) {
            throw new \InvalidArgumentException('A synchronization high-water mark cannot be negative.');
        }
    }
}
