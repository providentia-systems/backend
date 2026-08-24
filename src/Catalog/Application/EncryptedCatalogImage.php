<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

final readonly class EncryptedCatalogImage
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public int $keyVersion,
    ) {
    }
}
