<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

interface JsonHttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(
        string $url,
        array $headers,
        array $payload,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array;
}
