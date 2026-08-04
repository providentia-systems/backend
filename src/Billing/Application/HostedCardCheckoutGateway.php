<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

/**
 * Contract for redirect or embedded-token hosted card providers. Implementors
 * must never expose PAN, CVC, magnetic-stripe, or authentication values.
 */
interface HostedCardCheckoutGateway extends HostedCheckoutGateway
{
}
