<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

/**
 * Write-time policy for provider-profile-owned endpoints.
 *
 * Custom endpoints are owned at the same scope as the credential and are only
 * meaningful for the OpenAI-compatible and Ollama adapters. Public endpoints
 * must be HTTPS without userinfo, query, or fragment parts, and may never name
 * a literal private, loopback, or link-local address. The deliberately
 * separate LAN policy (AI_ALLOW_PRIVATE_NETWORK_ENDPOINTS) additionally
 * permits plain HTTP and private or loopback hosts for Ollama endpoints only.
 */
final readonly class ProfileEndpointPolicy
{
    public const MAX_LENGTH = 300;

    public function __construct(private bool $allowPrivateNetworkEndpoints = false)
    {
    }

    public function allowsPrivateNetworkEndpoints(): bool
    {
        return $this->allowPrivateNetworkEndpoints;
    }

    /** Only these adapters accept a profile-owned endpoint at all. */
    public function providerOwnsEndpoint(string $providerId): bool
    {
        return in_array($providerId, ['openai-compatible', 'ollama'], true);
    }

    public function permitsWrite(string $endpoint, string $providerId): bool
    {
        if (
            ! $this->providerOwnsEndpoint($providerId)
            || $endpoint === ''
            || strlen($endpoint) > self::MAX_LENGTH
            || preg_match('/[\s\x00-\x1F\x7F]/', $endpoint) === 1
        ) {
            return false;
        }
        $parts = parse_url($endpoint);
        if (
            $parts === false
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            return false;
        }
        if ($scheme === 'https') {
            // Literal private, loopback, and link-local addresses are always
            // rejected for HTTPS endpoints; a LAN Ollama endpoint uses the
            // explicit HTTP opt-in below instead.
            return ! $this->isPrivateLiteralAddress($host);
        }

        return $providerId === 'ollama' && $this->allowPrivateNetworkEndpoints;
    }

    private function isPrivateLiteralAddress(string $host): bool
    {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $bare,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
