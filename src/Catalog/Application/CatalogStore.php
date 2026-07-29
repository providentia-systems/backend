<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;

interface CatalogStore
{
    /** @return list<array<string, mixed>> */
    public function search(string $query, int $limit, int $offset): array;

    /**
     * @param array<string, mixed> $seed
     * @return array<string, int>
     */
    public function importSeed(array $seed, DateTimeImmutable $at): array;
}
