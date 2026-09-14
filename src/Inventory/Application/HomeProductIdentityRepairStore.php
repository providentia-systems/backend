<?php

declare(strict_types=1);

namespace Providentia\Inventory\Application;

use DateTimeImmutable;

interface HomeProductIdentityRepairStore
{
    /** @return list<array<string, mixed>> */
    public function scan(string $homeId, ?string $afterId, int $limit): array;

    /**
     * Must run inside the caller's transaction; an updated result is published
     * to the change feed before that same transaction commits.
     *
     * @return array<string, mixed>
     */
    public function repair(string $homeId, string $id, int $expectedRevision, DateTimeImmutable $at): array;
}
