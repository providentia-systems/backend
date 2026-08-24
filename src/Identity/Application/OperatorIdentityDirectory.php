<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateTimeImmutable;

/**
 * Privacy allowlist for the administration account directory. Implementations
 * expose identity metadata, platform roles, and active-session counts only.
 */
interface OperatorIdentityDirectory
{
    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function operatorAccounts(
        string $search,
        ?string $status,
        int $limit,
        int $offset,
        DateTimeImmutable $now,
    ): array;

    /** @return array<string, mixed>|null */
    public function operatorAccount(string $userId, DateTimeImmutable $now): ?array;
}
