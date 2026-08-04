<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

use Providentia\SharedKernel\Application\Problem;

final class CheckoutGatewayRegistry
{
    /** @var array<string, HostedCheckoutGateway> */
    private array $gateways = [];

    /** @param list<HostedCheckoutGateway> $gateways */
    public function __construct(array $gateways)
    {
        foreach ($gateways as $gateway) {
            $provider = mb_strtolower(trim($gateway->provider()));
            if (! in_array($provider, ['paypal', 'hosted_card'], true)) {
                throw new \InvalidArgumentException('Unsupported hosted-checkout provider.');
            }
            if (isset($this->gateways[$provider])) {
                throw new \InvalidArgumentException('Duplicate hosted-checkout provider.');
            }
            $this->gateways[$provider] = $gateway;
        }
    }

    public function require(string $provider): HostedCheckoutGateway
    {
        $provider = mb_strtolower(trim($provider));
        if (! isset($this->gateways[$provider])) {
            throw new Problem(
                422,
                'Checkout provider unavailable',
                'The selected hosted-checkout provider is not enabled.',
            );
        }

        return $this->gateways[$provider];
    }
}
