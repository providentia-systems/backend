<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Http;

use Providentia\AiIntegration\Application\AiProviderException;

final readonly class EndpointPolicy
{
    /**
     * @param list<string> $allowedHosts deployment-configured endpoint hosts
     * @param bool $allowPrivateNetworks deployment-endpoint LAN opt-in
     *        (AI_ALLOW_PRIVATE_ENDPOINTS)
     * @param bool $allowPrivateProfileEndpoints profile-endpoint LAN opt-in
     *        (AI_ALLOW_PRIVATE_NETWORK_ENDPOINTS); write-time validation only
     *        stores plain-HTTP or private endpoints for Ollama profiles, so
     *        this request-time lane never widens another provider's policy
     */
    public function __construct(
        private array $allowedHosts,
        private bool $allowPrivateNetworks,
        private bool $allowPrivateProfileEndpoints = false,
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
        if ($host === '') {
            throw $this->rejected();
        }
        // A deployment-configured host keeps the original allowlist policy; any
        // other host can only be a profile-owned endpoint that already passed
        // owner-scoped write-time validation, and is re-checked here under the
        // same https-or-LAN-opt-in rule before every request.
        $allowPrivate = in_array($host, $this->allowedHosts, true)
            ? $this->allowPrivateNetworks
            : $this->allowPrivateProfileEndpoints;
        if ($scheme !== 'https' && ! ($scheme === 'http' && $allowPrivate)) {
            throw $this->rejected();
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) === false
            ? gethostbynamel($host)
            : [$host];
        if ($addresses === false || $addresses === []) {
            throw new AiProviderException('provider_dns_failed', 'The configured provider host did not resolve.');
        }
        foreach ($addresses as $address) {
            $public = filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
            if ($public === false && ! $allowPrivate) {
                throw $this->rejected();
            }
        }
    }

    private function rejected(): AiProviderException
    {
        return new AiProviderException(
            'provider_endpoint_rejected',
            'The configured provider endpoint is not allowed by server policy.',
        );
    }
}
