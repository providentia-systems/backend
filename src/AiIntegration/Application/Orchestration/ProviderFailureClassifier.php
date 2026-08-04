<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application\Orchestration;

final class ProviderFailureClassifier
{
    /** @var list<string> */
    private const RETRYABLE = [
        'provider_unreachable',
        'provider_timeout',
        'provider_rate_limited',
        'provider_http_error',
        'provider_incomplete',
        'provider_empty_output',
        'provider_invalid_json',
        'provider_response_too_large',
    ];

    public function permitsFailover(string $safeCode): bool
    {
        return in_array($safeCode, self::RETRYABLE, true);
    }
}
