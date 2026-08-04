<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Application;

final readonly class DataArtifact
{
    public function __construct(
        public string $reference,
        public string $nonce,
        public string $sha256,
        public int $size,
    ) {
    }
}
