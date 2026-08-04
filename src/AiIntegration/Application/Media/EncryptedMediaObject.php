<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application\Media;

final readonly class EncryptedMediaObject
{
    public function __construct(
        public string $objectKey,
        public string $wrappedKey,
        public string $wrapNonce,
        public int $keyVersion,
        public string $sha256,
        public int $plaintextBytes,
    ) {
    }
}
