<?php

declare(strict_types=1);

namespace Providentia\Billing\Infrastructure\Provider;

use DateTimeImmutable;
use Providentia\Billing\Application\BillingHttpRequest;
use Providentia\Billing\Application\BillingHttpResponse;
use Providentia\Billing\Application\BillingHttpTransport;
use Providentia\Billing\Application\BillingProviderException;
use Providentia\Billing\Application\HostedCardCheckoutGateway;
use Providentia\Billing\Application\HostedCheckoutRequest;
use Providentia\Billing\Application\HostedCheckoutSession;
use Providentia\Billing\Application\HostedCheckoutWebhook;
use Providentia\SharedKernel\Application\Clock;

final readonly class HostedCardRedirectAdapter implements HostedCardCheckoutGateway
{
    public function __construct(
        private BillingHttpTransport $http,
        private Clock $clock,
        private string $apiBase,
        private string $checkoutPath,
        /** @var list<string> */
        private array $allowedRedirectHosts,
        private string $apiKey,
        private string $webhookSecret,
        private string $webhookSignatureHeader,
        private string $webhookTimestampHeader,
        private int $webhookToleranceSeconds,
        private int $timeoutSeconds,
        private int $maximumResponseBytes,
    ) {
        $parts = parse_url($apiBase);
        if (
            $parts === false
            || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true)
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! str_starts_with($checkoutPath, '/')
            || str_contains($checkoutPath, '..')
            || str_contains($checkoutPath, '?')
            || str_contains($checkoutPath, '#')
            || $allowedRedirectHosts === []
            || trim($apiKey) === ''
            || strlen($webhookSecret) < 32
            || preg_match('/^[A-Za-z0-9-]+$/', $webhookSignatureHeader) !== 1
            || preg_match('/^[A-Za-z0-9-]+$/', $webhookTimestampHeader) !== 1
        ) {
            throw new \InvalidArgumentException('Hosted-card redirect configuration is invalid.');
        }
        foreach ($allowedRedirectHosts as $host) {
            if (
                $host !== mb_strtolower($host)
                || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1
            ) {
                throw new \InvalidArgumentException('Hosted-card redirect host is invalid.');
            }
        }
    }

    public function provider(): string
    {
        return 'hosted_card';
    }

    public function createSession(HostedCheckoutRequest $request): HostedCheckoutSession
    {
        $payload = [
            'checkout_reference' => $request->idempotencyKey,
            'price' => [
                'internal_id' => $request->priceId,
                'code' => $request->priceCode,
                'provider_reference' => $request->providerPriceReference,
                'currency' => $request->currency,
                'amount_minor' => $request->amountMinor,
                'interval_unit' => $request->intervalUnit,
                'interval_count' => $request->intervalCount,
            ],
            'promotion_code' => $request->promotionCode,
            'entitlements' => $request->entitlements,
            'redirects' => [
                'success_url' => $request->successUrl,
                'cancel_url' => $request->cancelUrl,
            ],
        ];
        $response = $this->http->send(new BillingHttpRequest(
            'POST',
            rtrim($this->apiBase, '/') . $this->checkoutPath,
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Idempotency-Key' => $request->idempotencyKey,
            ],
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->timeoutSeconds,
            $this->maximumResponseBytes,
        ));
        $body = $this->successfulObject($response);
        $id = $this->requiredString($body, 'id', 191);
        $redirectUrl = $this->requiredString($body, 'redirect_url', 2048);
        $redirectHost = parse_url($redirectUrl, PHP_URL_HOST);
        $redirectParts = parse_url($redirectUrl);
        if (
            ! str_starts_with($redirectUrl, 'https://')
            || ! is_string($redirectHost)
            || ! in_array(mb_strtolower($redirectHost), $this->allowedRedirectHosts, true)
            || $redirectParts === false
            || isset($redirectParts['user'])
            || isset($redirectParts['pass'])
        ) {
            throw $this->invalidResponse();
        }
        $expiresAt = $this->date($body['expires_at'] ?? null, 'checkout expiration');
        if ($expiresAt <= $this->clock->now()) {
            throw $this->invalidResponse();
        }

        return new HostedCheckoutSession($id, $redirectUrl, $expiresAt);
    }

    public function verifyWebhook(string $rawBody, array $headers): HostedCheckoutWebhook
    {
        $timestamp = $this->requiredHeader($headers, $this->webhookTimestampHeader, 20);
        if (preg_match('/^[0-9]{10,13}$/', $timestamp) !== 1) {
            throw $this->invalidWebhook();
        }
        $timestampSeconds = (int) mb_substr($timestamp, 0, 10);
        if (abs($this->clock->now()->getTimestamp() - $timestampSeconds) > $this->webhookToleranceSeconds) {
            throw new BillingProviderException(
                'provider_webhook_expired',
                'The hosted-card webhook timestamp is outside the accepted window.',
            );
        }
        $provided = $this->requiredHeader($headers, $this->webhookSignatureHeader, 256);
        if (str_starts_with($provided, 'v1=')) {
            $provided = substr($provided, 3);
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->webhookSecret);
        if (preg_match('/^[a-f0-9]{64}$/i', $provided) !== 1 || ! hash_equals($expected, $provided)) {
            throw new BillingProviderException(
                'provider_webhook_signature_invalid',
                'The hosted-card webhook signature could not be verified.',
            );
        }
        $event = $this->decodeObject($rawBody, 'provider_invalid_webhook');
        $data = $event['data'] ?? null;
        if (! is_array($data) || array_is_list($data)) {
            throw $this->invalidWebhook();
        }
        $eventType = $this->requiredString($event, 'type', 64);
        if (
            ! in_array($eventType, [
                'checkout.completed',
                'subscription.updated',
                'subscription.cancelled',
                'subscription.past_due',
            ], true)
        ) {
            throw new BillingProviderException(
                'provider_event_unsupported',
                'The hosted-card event is not configured for the billing state machine.',
            );
        }
        $status = $data['subscription_status'] ?? null;
        if ($status !== null && ! is_string($status)) {
            throw $this->invalidWebhook();
        }
        $periodEnd = $data['current_period_ends_at'] ?? null;

        return new HostedCheckoutWebhook(
            $this->requiredString($event, 'id', 191),
            $eventType,
            $this->requiredString($data, 'checkout_reference', 191),
            $this->optionalString($data, 'subscription_reference', 191),
            $this->optionalString($data, 'customer_reference', 191),
            $status,
            $periodEnd === null ? null : $this->date($periodEnd, 'billing period end'),
            $this->date($event['created_at'] ?? null, 'event creation time'),
        );
    }

    /**
     * @return array<string, mixed> */
    private function successfulObject(BillingHttpResponse $response): array
    {
        if ($response->status < 200 || $response->status >= 300) {
            throw new BillingProviderException(
                match ($response->status) {
                    401, 403 => 'provider_authentication_failed',
                    408, 504 => 'provider_timeout',
                    409 => 'provider_conflict',
                    429 => 'provider_rate_limited',
                    default => 'provider_http_error',
                },
                'The hosted-card provider rejected or could not complete the request.',
            );
        }

        return $this->decodeObject($response->body, 'provider_invalid_response');
    }

    /**
     * @return array<string, mixed> */
    private function decodeObject(string $json, string $code): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BillingProviderException($code, 'The hosted-card provider returned invalid JSON.');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new BillingProviderException(
                $code,
                'The hosted-card provider returned an invalid JSON object.',
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, list<string>> $headers */
    private function requiredHeader(array $headers, string $name, int $maximumLength): string
    {
        foreach ($headers as $candidate => $values) {
            if (strcasecmp($candidate, $name) !== 0) {
                continue;
            }
            $value = trim((string) ($values[0] ?? ''));
            if ($value !== '' && strlen($value) <= $maximumLength) {
                return $value;
            }
        }

        throw $this->invalidWebhook();
    }

    /**
     * @param array<string, mixed> $source */
    private function requiredString(array $source, string $key, int $maximumLength): string
    {
        $value = $source[$key] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > $maximumLength) {
            throw $this->invalidResponse();
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source */
    private function optionalString(array $source, string $key, int $maximumLength): ?string
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '' || strlen($value) > $maximumLength) {
            throw $this->invalidWebhook();
        }

        return $value;
    }

    private function date(mixed $value, string $label): DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            throw $this->invalidWebhook();
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BillingProviderException(
                'provider_invalid_webhook',
                'The hosted-card ' . $label . ' is invalid.',
            );
        }
    }

    private function invalidResponse(): BillingProviderException
    {
        return new BillingProviderException(
            'provider_invalid_response',
            'The hosted-card provider returned an incomplete billing response.',
        );
    }

    private function invalidWebhook(): BillingProviderException
    {
        return new BillingProviderException(
            'provider_invalid_webhook',
            'The hosted-card webhook is incomplete or invalid.',
        );
    }
}
