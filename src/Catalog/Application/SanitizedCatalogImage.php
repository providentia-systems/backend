<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

final readonly class SanitizedCatalogImage
{
    public function __construct(
        public string $bytes,
        public string $digest,
        public string $mediaType,
        public int $width,
        public int $height,
    ) {
    }
}
