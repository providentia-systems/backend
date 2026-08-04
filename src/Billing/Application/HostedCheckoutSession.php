<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

use DateTimeImmutable;

final readonly class HostedCheckoutSession
{
    public function __construct(
        public string $providerReference,
        public string $redirectUrl,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
