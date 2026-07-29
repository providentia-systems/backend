<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

final class CatalogQueryService
{
    public function __construct(private readonly CatalogStore $catalog)
    {
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query, int $limit, int $offset): array
    {
        return $this->catalog->search(
            mb_substr(trim($query), 0, 191),
            min(100, max(1, $limit)),
            max(0, $offset),
        );
    }
}
