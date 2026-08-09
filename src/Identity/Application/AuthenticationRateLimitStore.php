<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateTimeImmutable;

interface AuthenticationRateLimitStore
{
    public function consume(
        string $bucketHash,
        DateTimeImmutable $now,
        int $windowSeconds,
        int $maximumAttempts,
        int $blockSeconds,
    ): bool;

    public function purgeInactive(
        DateTimeImmutable $now,
        DateTimeImmutable $retentionCutoff,
        int $limit,
    ): int;
}
