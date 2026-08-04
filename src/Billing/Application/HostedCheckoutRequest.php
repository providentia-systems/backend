<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

final readonly class HostedCheckoutRequest
{
    /**
     * Cardholder data is deliberately absent. Payment credentials are
     * collected exclusively on the provider-hosted surface.
     *
     * @param array<string, bool|int|string|null> $entitlements
     */
    public function __construct(
        public string $idempotencyKey,
        public string $homeId,
        public string $priceId,
        public string $priceCode,
        public ?string $providerPriceReference,
        public string $currency,
        public int $amountMinor,
        public string $intervalUnit,
        public int $intervalCount,
        public string $successUrl,
        public string $cancelUrl,
        public ?string $promotionCode,
        public array $entitlements,
    ) {
    }
}
