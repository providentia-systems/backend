<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use DateTimeImmutable;

interface CatalogImportHomeProductGateway
{
    public function matchingActiveId(
        string $homeId,
        ?string $productId,
        ?string $packId,
        ?string $normalizedPrivateName,
    ): ?string;

    public function create(
        string $id,
        string $homeId,
        ?string $productId,
        ?string $packId,
        ?string $privateName,
        ?string $normalizedPrivateName,
        ?string $originalPackText,
        DateTimeImmutable $at,
    ): void;
}
