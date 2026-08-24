<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

interface CatalogImageCipher
{
    public function available(): bool;

    public function encrypt(string $plaintext, string $associatedData): EncryptedCatalogImage;

    public function decrypt(EncryptedCatalogImage $encrypted, string $associatedData): string;
}
