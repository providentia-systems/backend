<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

interface PayPalHostedCheckoutGateway extends HostedCheckoutGateway
{
    /**
     * Capture a signature-verified approval after the webhook receipt has
     * been durably claimed. The provider idempotency key is the event ID.
     */
    public function captureApprovedOrder(HostedCheckoutWebhook $approved): HostedCheckoutWebhook;
}
