<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

final readonly class BillingHttpRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public string $body,
        public int $timeoutSeconds,
        public int $maximumResponseBytes,
    ) {
    }
}
