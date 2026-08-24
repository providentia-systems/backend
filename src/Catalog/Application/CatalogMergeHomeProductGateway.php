<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

interface CatalogMergeHomeProductGateway
{
    /** @return list<string> */
    public function references(string $productId): array;

    public function pointsTo(string $homeProductId, string $productId): bool;

    public function relink(string $homeProductId, string $fromProductId, string $toProductId): bool;
}
