<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

interface PublishedPackReader
{
    public function lockPublishedPack(string $productId, string $packId): bool;
}
