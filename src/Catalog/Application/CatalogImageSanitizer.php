<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

interface CatalogImageSanitizer
{
    public function sanitize(string $uploadedBytes): SanitizedCatalogImage;
}
