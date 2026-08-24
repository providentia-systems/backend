<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

interface PublishedCategoryReader
{
    /** @return list<array{id: string, canonicalName: string, revision: int}> */
    public function publishedCategories(string $query, int $limit, int $offset): array;

    /** @return array{id: string, canonicalName: string, revision: int}|null */
    public function publishedCategory(string $id): ?array;
}
