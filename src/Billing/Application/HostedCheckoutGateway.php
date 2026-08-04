<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

interface HostedCheckoutGateway
{
    public function provider(): string;

    public function createSession(HostedCheckoutRequest $request): HostedCheckoutSession;

    /**
     * Implementations must authenticate the webhook before returning a
     * normalized event. Raw payloads are never persisted by the application.
     *
     * @param array<string, list<string>> $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): HostedCheckoutWebhook;
}
