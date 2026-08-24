<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

final readonly class CatalogImageContent
{
    public function __construct(
        public string $bytes,
        public string $mediaType,
        public string $digest,
        public int $width,
        public int $height,
        public string $altText,
        public int $revision,
    ) {
    }
}
