<?php

declare(strict_types=1);

namespace Providentia\Billing\Infrastructure\Http;

use Providentia\Billing\Application\BillingHttpRequest;
use Providentia\Billing\Application\BillingHttpResponse;
use Providentia\Billing\Application\BillingHttpTransport;
use Providentia\Billing\Application\BillingProviderException;

final readonly class StreamBillingHttpTransport implements BillingHttpTransport
{
    public function __construct(private BillingEndpointPolicy $endpoints)
    {
    }

    public function send(BillingHttpRequest $request): BillingHttpResponse
    {
        $this->endpoints->assertAllowed($request->url);
        if (! in_array($request->method, ['POST'], true)) {
            throw new BillingProviderException(
                'provider_method_rejected',
                'The billing provider HTTP method is not allowed.',
            );
        }
        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            if (
                preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1
                || str_contains($value, "\n")
                || str_contains($value, "\r")
            ) {
                throw new BillingProviderException(
                    'provider_header_rejected',
                    'A billing provider header was rejected.',
                );
            }
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $request->method,
                'header' => implode("\r\n", $headerLines),
                'content' => $request->body,
                'timeout' => max(1, min(30, $request->timeoutSeconds)),
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);
        $stream = @fopen($request->url, 'rb', false, $context);
        if ($stream === false) {
            throw new BillingProviderException(
                'provider_unreachable',
                'The configured billing provider is unreachable.',
            );
        }
        try {
            $body = stream_get_contents($stream, $request->maximumResponseBytes + 1);
            $metadata = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        if (! is_string($body) || strlen($body) > $request->maximumResponseBytes) {
            throw new BillingProviderException(
                'provider_response_too_large',
                'The billing provider response exceeded its configured limit.',
            );
        }
        $headers = $this->responseHeaders($metadata['wrapper_data'] ?? []);

        return new BillingHttpResponse($headers['status'], $headers['headers'], $body);
    }

    /**
     * @param mixed $wrapperData
     * @return array{status: int, headers: array<string, list<string>>}
     */
    private function responseHeaders(mixed $wrapperData): array
    {
        $lines = is_array($wrapperData) ? $wrapperData : [];
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $matches) === 1) {
                $status = (int) $matches[1];
                $headers = [];
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $name = mb_strtolower(trim(substr($line, 0, $separator)));
            $headers[$name][] = trim(substr($line, $separator + 1));
        }

        return ['status' => $status, 'headers' => $headers];
    }
}
