<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Http;

use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Application\JsonHttpClient;

final readonly class StreamJsonHttpClient implements JsonHttpClient
{
    public function __construct(private EndpointPolicy $endpoints)
    {
    }

    public function post(
        string $url,
        array $headers,
        array $payload,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        $this->endpoints->assertAllowed($url);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headerLines = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $name => $value) {
            if (
                preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1
                || str_contains($value, "\n")
                || str_contains($value, "\r")
            ) {
                throw new AiProviderException('provider_header_rejected', 'A provider header was rejected.');
            }
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $encoded,
                'timeout' => max(1, min(120, $timeoutSeconds)),
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new AiProviderException('provider_unreachable', 'The configured provider is unreachable.');
        }
        try {
            $body = stream_get_contents($stream, $maxResponseBytes + 1);
            $metadata = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        if (! is_string($body) || strlen($body) > $maxResponseBytes) {
            throw new AiProviderException('provider_response_too_large', 'The provider response exceeded its limit.');
        }
        $status = $this->status($metadata['wrapper_data'] ?? []);
        if ($status < 200 || $status >= 300) {
            $code = match ($status) {
                401, 403 => 'provider_authentication_failed',
                408, 504 => 'provider_timeout',
                429 => 'provider_rate_limited',
                default => 'provider_http_error',
            };
            throw new AiProviderException($code, 'The provider rejected or could not complete the request.');
        }
        return ProviderJsonDecoder::httpResponse($body);
    }

    /** @param mixed $wrapperData */
    private function status(mixed $wrapperData): int
    {
        $lines = is_array($wrapperData) ? $wrapperData : [];
        $status = 0;
        foreach ($lines as $line) {
            if (is_string($line) && preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
