<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

use Providentia\SharedKernel\Application\Problem;

final readonly class BillingConfiguration
{
    /** @param list<string> $enabledProviders */
    public function __construct(
        public bool $enabled,
        public array $enabledProviders,
    ) {
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled) {
            throw new Problem(
                503,
                'Billing unavailable',
                'Billing is disabled for this deployment.',
            );
        }
    }
}
