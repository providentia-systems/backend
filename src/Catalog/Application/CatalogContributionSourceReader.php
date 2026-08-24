<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

interface CatalogContributionSourceReader
{
    /**
     * Locks the active source where the database supports row locks.
     *
     * @return array{productId: string|null, packId: string|null}|null
     */
    public function activeHomeProduct(string $homeId, string $homeProductId): ?array;
}
