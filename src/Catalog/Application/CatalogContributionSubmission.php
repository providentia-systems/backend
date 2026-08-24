<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

final readonly class CatalogContributionSubmission
{
    /** @param array<string, mixed> $contribution */
    public function __construct(
        public bool $created,
        public array $contribution,
    ) {
    }
}
