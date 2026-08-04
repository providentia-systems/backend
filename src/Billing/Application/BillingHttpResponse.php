<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

final readonly class BillingHttpResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }
}
