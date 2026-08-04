<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use DateTimeImmutable;

final readonly class SyncBackfillRecord
{
    /** @param array<string, mixed> $representation */
    public function __construct(
        public string $homeId,
        public string $entityType,
        public string $entityId,
        public int $revision,
        public array $representation,
        public ?string $actorUserId,
        public DateTimeImmutable $changedAt,
    ) {
        if ($this->revision < 1) {
            throw new \InvalidArgumentException('A backfill record revision must be positive.');
        }
    }
}
