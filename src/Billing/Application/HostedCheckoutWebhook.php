<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

use DateTimeImmutable;

final readonly class HostedCheckoutWebhook
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $checkoutReference,
        public ?string $subscriptionReference,
        public ?string $customerReference,
        public ?string $subscriptionStatus,
        public ?DateTimeImmutable $currentPeriodEndsAt,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
