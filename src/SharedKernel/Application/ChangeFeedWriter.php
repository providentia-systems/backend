<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application;

use DateTimeImmutable;

/**
 * Publishes a normalized home-scoped resource representation for incremental sync.
 *
 * Implementations participate in the caller's database transaction; application
 * services call this only after the authoritative domain write succeeds.
 */
interface ChangeFeedWriter
{
    /** @param array<string, mixed> $representation */
    public function put(
        string $homeId,
        string $actorUserId,
        string $entityType,
        string $entityId,
        int $revision,
        array $representation,
        DateTimeImmutable $at,
    ): int;
}
