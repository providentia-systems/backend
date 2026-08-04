<?php

declare(strict_types=1);

namespace Providentia\Billing\Infrastructure\Http;

use Providentia\Billing\Application\BillingProviderException;

final readonly class BillingEndpointPolicy
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private array $allowedHosts,
        private bool $allowPrivateNetworks,
    ) {
    }

    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (
            $parts === false
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || isset($parts['query'])
        ) {
            throw $this->rejected();
        }
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if (
            $host === ''
            || ! in_array($host, $this->allowedHosts, true)
            || ($scheme !== 'https' && ! ($scheme === 'http' && $this->allowPrivateNetworks))
        ) {
            throw $this->rejected();
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) === false
            ? gethostbynamel($host)
            : [$host];
        if ($addresses === false || $addresses === []) {
            throw new BillingProviderException(
                'provider_dns_failed',
                'The configured billing provider host did not resolve.',
            );
        }
        foreach ($addresses as $address) {
            $public = filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
            if ($public === false && ! $this->allowPrivateNetworks) {
                throw $this->rejected();
            }
        }
    }

    private function rejected(): BillingProviderException
    {
        return new BillingProviderException(
            'provider_endpoint_rejected',
            'The configured billing provider endpoint is not allowed by server policy.',
        );
    }
}
