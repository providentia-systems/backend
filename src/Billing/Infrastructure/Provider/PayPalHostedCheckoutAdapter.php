<?php

declare(strict_types=1);

namespace Providentia\Billing\Infrastructure\Provider;

use DateTimeImmutable;
use Providentia\Billing\Application\BillingHttpRequest;
use Providentia\Billing\Application\BillingHttpResponse;
use Providentia\Billing\Application\BillingHttpTransport;
use Providentia\Billing\Application\BillingProviderException;
use Providentia\Billing\Application\HostedCheckoutRequest;
use Providentia\Billing\Application\HostedCheckoutSession;
use Providentia\Billing\Application\HostedCheckoutWebhook;
use Providentia\Billing\Application\PayPalHostedCheckoutGateway;
use Providentia\SharedKernel\Application\Clock;

final class PayPalHostedCheckoutAdapter implements PayPalHostedCheckoutGateway
{
    private ?string $accessToken = null;
    private ?DateTimeImmutable $accessTokenExpiresAt = null;

    public function __construct(
        private readonly BillingHttpTransport $http,
        private readonly Clock $clock,
        private readonly string $apiBase,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $webhookId,
        private readonly int $timeoutSeconds,
        private readonly int $maximumResponseBytes,
    ) {
        if (
            ! in_array($apiBase, [
                'https://api-m.paypal.com',
                'https://api-m.sandbox.paypal.com',
            ], true)
            || trim($clientId) === ''
            || trim($clientSecret) === ''
            || preg_match('/^[A-Za-z0-9]{1,50}$/', $webhookId) !== 1
        ) {
            throw new \InvalidArgumentException('PayPal hosted-checkout configuration is invalid.');
        }
    }

    public function provider(): string
    {
        return 'paypal';
    }

    public function createSession(HostedCheckoutRequest $request): HostedCheckoutSession
    {
        if ($request->promotionCode !== null) {
            throw new BillingProviderException(
                'paypal_promotion_plan_required',
                'PayPal promotions require a separately mapped provider plan or price.',
            );
        }

        return $request->intervalUnit === 'one_time'
            ? $this->createOrder($request)
            : $this->createSubscription($request);
    }

    public function verifyWebhook(string $rawBody, array $headers): HostedCheckoutWebhook
    {
        $event = $this->decodeObject($rawBody, 'provider_invalid_webhook');
        $verification = $this->postJson('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $this->requiredHeader($headers, 'PAYPAL-AUTH-ALGO', 100),
            'cert_url' => $this->certificateUrl(
                $this->requiredHeader($headers, 'PAYPAL-CERT-URL', 500),
            ),
            'transmission_id' => $this->requiredHeader(
                $headers,
                'PAYPAL-TRANSMISSION-ID',
                50,
            ),
            'transmission_sig' => $this->requiredHeader(
                $headers,
                'PAYPAL-TRANSMISSION-SIG',
                500,
            ),
            'transmission_time' => $this->requiredHeader(
                $headers,
                'PAYPAL-TRANSMISSION-TIME',
                100,
            ),
            'webhook_id' => $this->webhookId,
            'webhook_event' => $event,
        ]);
        if (($verification['verification_status'] ?? null) !== 'SUCCESS') {
            throw new BillingProviderException(
                'provider_webhook_signature_invalid',
                'The PayPal webhook signature could not be verified.',
            );
        }

        return $this->normalizeWebhook($event);
    }

    public function captureApprovedOrder(HostedCheckoutWebhook $approved): HostedCheckoutWebhook
    {
        if ($approved->eventType !== 'checkout.approved') {
            throw new BillingProviderException(
                'provider_capture_not_approved',
                'Only an authenticated PayPal order approval may be captured.',
            );
        }
        $capture = $this->postJson(
            '/v2/checkout/orders/' . rawurlencode($approved->checkoutReference) . '/capture',
            (object) [],
            $approved->eventId . '-capture',
        );
        if (($capture['status'] ?? null) !== 'COMPLETED') {
            throw new BillingProviderException(
                'provider_capture_incomplete',
                'PayPal did not confirm completion of the approved order.',
            );
        }

        return $this->orderWebhook($approved->eventId, $approved->occurredAt, $capture);
    }

    private function createOrder(HostedCheckoutRequest $request): HostedCheckoutSession
    {
        $response = $this->postJson('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $request->idempotencyKey,
                'custom_id' => $request->idempotencyKey,
                'description' => mb_substr($request->priceCode, 0, 127),
                'amount' => [
                    'currency_code' => $request->currency,
                    'value' => $this->paypalAmount($request->amountMinor, $request->currency),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'shipping_preference' => 'NO_SHIPPING',
                        'user_action' => 'PAY_NOW',
                        'return_url' => $request->successUrl,
                        'cancel_url' => $request->cancelUrl,
                    ],
                ],
            ],
        ], $request->idempotencyKey);
        $id = $this->requiredString($response, 'id', 191);

        return new HostedCheckoutSession(
            $id,
            $this->approvalLink($response, ['payer-action', 'approve']),
            $this->clock->now()->modify('+3 hours'),
        );
    }

    private function createSubscription(HostedCheckoutRequest $request): HostedCheckoutSession
    {
        if ($request->providerPriceReference === null || $request->providerPriceReference === '') {
            throw new BillingProviderException(
                'paypal_plan_reference_missing',
                'The selected price is not mapped to an active PayPal subscription plan.',
            );
        }
        $response = $this->postJson('/v1/billing/subscriptions', [
            'plan_id' => $request->providerPriceReference,
            'custom_id' => $request->idempotencyKey,
            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => $request->successUrl,
                'cancel_url' => $request->cancelUrl,
            ],
        ], $request->idempotencyKey);
        $id = $this->requiredString($response, 'id', 191);

        return new HostedCheckoutSession(
            $id,
            $this->approvalLink($response, ['approve', 'payer-action']),
            $this->clock->now()->modify('+3 hours'),
        );
    }

    /**
     *
     * @param array<string, mixed> $event
     */
    private function normalizeWebhook(array $event): HostedCheckoutWebhook
    {
        $providerEventId = $this->requiredString($event, 'id', 191);
        $providerEventType = $this->requiredString($event, 'event_type', 100);
        $occurredAt = $this->date($event['create_time'] ?? null, 'event creation time');
        $resource = $event['resource'] ?? null;
        if (! is_array($resource) || array_is_list($resource)) {
            throw $this->invalidWebhook();
        }

        return match ($providerEventType) {
            'BILLING.SUBSCRIPTION.ACTIVATED' => $this->subscriptionWebhook(
                $providerEventId,
                'checkout.completed',
                'active',
                $occurredAt,
                $resource,
            ),
            'BILLING.SUBSCRIPTION.UPDATED' => $this->subscriptionWebhook(
                $providerEventId,
                'subscription.updated',
                $this->subscriptionStatus($resource['status'] ?? null),
                $occurredAt,
                $resource,
            ),
            'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED' =>
                $this->subscriptionWebhook(
                    $providerEventId,
                    'subscription.cancelled',
                    'cancelled',
                    $occurredAt,
                    $resource,
                ),
            'BILLING.SUBSCRIPTION.SUSPENDED' => $this->subscriptionWebhook(
                $providerEventId,
                'subscription.updated',
                'paused',
                $occurredAt,
                $resource,
            ),
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' =>
                $this->subscriptionWebhook(
                    $providerEventId,
                    'subscription.past_due',
                    'past_due',
                    $occurredAt,
                    $resource,
                ),
            'CHECKOUT.ORDER.APPROVED' => $this->approvedOrderWebhook(
                $providerEventId,
                $occurredAt,
                $resource,
            ),
            'CHECKOUT.ORDER.COMPLETED' => $this->orderWebhook(
                $providerEventId,
                $occurredAt,
                $resource,
            ),
            'PAYMENT.CAPTURE.COMPLETED' => $this->captureWebhook(
                $providerEventId,
                $occurredAt,
                $resource,
            ),
            default => throw new BillingProviderException(
                'provider_event_unsupported',
                'The PayPal event is not configured for the billing state machine.',
            ),
        };
    }

    /**
     * @param array<string, mixed> $resource */
    private function approvedOrderWebhook(
        string $eventId,
        DateTimeImmutable $occurredAt,
        array $resource,
    ): HostedCheckoutWebhook {
        $orderId = $this->requiredString($resource, 'id', 191);

        return new HostedCheckoutWebhook(
            $eventId,
            'checkout.approved',
            $orderId,
            null,
            $this->nestedString($resource, ['payer', 'payer_id']),
            null,
            null,
            $occurredAt,
        );
    }

    /**
     * @param array<string, mixed> $resource */
    private function orderWebhook(
        string $eventId,
        DateTimeImmutable $occurredAt,
        array $resource,
    ): HostedCheckoutWebhook {
        $orderId = $this->requiredString($resource, 'id', 191);

        return new HostedCheckoutWebhook(
            $eventId,
            'checkout.completed',
            $orderId,
            null,
            $this->nestedString($resource, ['payer', 'payer_id']),
            'active',
            null,
            $occurredAt,
        );
    }

    /**
     * @param array<string, mixed> $resource */
    private function captureWebhook(
        string $eventId,
        DateTimeImmutable $occurredAt,
        array $resource,
    ): HostedCheckoutWebhook {
        $orderId = $this->nestedString(
            $resource,
            ['supplementary_data', 'related_ids', 'order_id'],
        );
        if ($orderId === null || $orderId === '') {
            throw $this->invalidWebhook();
        }

        return new HostedCheckoutWebhook(
            $eventId,
            'checkout.completed',
            $orderId,
            null,
            null,
            'active',
            null,
            $occurredAt,
        );
    }

    /**
     * @param array<string, mixed> $resource */
    private function subscriptionWebhook(
        string $eventId,
        string $eventType,
        string $status,
        DateTimeImmutable $occurredAt,
        array $resource,
    ): HostedCheckoutWebhook {
        $subscriptionId = $this->requiredString($resource, 'id', 191);
        $periodEnd = $this->nestedString($resource, ['billing_info', 'next_billing_time']);

        return new HostedCheckoutWebhook(
            $eventId,
            $eventType,
            $subscriptionId,
            $subscriptionId,
            $this->nestedString($resource, ['subscriber', 'payer_id']),
            $status,
            $periodEnd === null ? null : $this->date($periodEnd, 'billing period end'),
            $occurredAt,
        );
    }

    private function subscriptionStatus(mixed $status): string
    {
        return match (mb_strtoupper(is_string($status) ? $status : '')) {
            'APPROVAL_PENDING' => 'trialing',
            'ACTIVE' => 'active',
            'SUSPENDED' => 'paused',
            'CANCELLED', 'EXPIRED' => 'cancelled',
            default => 'past_due',
        };
    }

    /**
     *
     * @param array<string, mixed>|object $payload
     *
     * @return array<string, mixed>
     */
    private function postJson(string $path, array|object $payload, ?string $requestId = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken(),
        ];
        if ($requestId !== null) {
            $headers['PayPal-Request-Id'] = mb_substr($requestId, 0, 108);
        }
        $response = $this->http->send(new BillingHttpRequest(
            'POST',
            $this->apiBase . $path,
            $headers,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->timeoutSeconds,
            $this->maximumResponseBytes,
        ));

        return $this->successfulObject($response);
    }

    private function accessToken(): string
    {
        $now = $this->clock->now();
        if (
            $this->accessToken !== null
            && $this->accessTokenExpiresAt !== null
            && $this->accessTokenExpiresAt > $now
        ) {
            return $this->accessToken;
        }
        $response = $this->http->send(new BillingHttpRequest(
            'POST',
            $this->apiBase . '/v1/oauth2/token',
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            'grant_type=client_credentials',
            $this->timeoutSeconds,
            $this->maximumResponseBytes,
        ));
        $token = $this->successfulObject($response);
        $accessToken = $token['access_token'] ?? null;
        $expiresIn = $token['expires_in'] ?? null;
        if (! is_string($accessToken) || $accessToken === '' || ! is_int($expiresIn)) {
            throw new BillingProviderException(
                'provider_invalid_response',
                'PayPal returned an invalid OAuth response.',
            );
        }
        $this->accessToken = $accessToken;
        $this->accessTokenExpiresAt = $now->modify('+' . max(30, $expiresIn - 60) . ' seconds');

        return $this->accessToken;
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
                'PayPal rejected or could not complete the billing request.',
            );
        }

        return $this->decodeObject($response->body, 'provider_invalid_response');
    }

    /**
     * @return array<string, mixed> */
    private function decodeObject(string $json, string $code): array
    {
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BillingProviderException($code, 'PayPal returned invalid JSON.');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new BillingProviderException($code, 'PayPal returned an invalid JSON object.');
        }

        return $decoded;
    }

    /**
     *
     * @param array<string, mixed> $response
     *
     * @param list<string> $relations
     */
    private function approvalLink(array $response, array $relations): string
    {
        $links = $response['links'] ?? null;
        if (! is_array($links)) {
            throw new BillingProviderException(
                'provider_invalid_response',
                'PayPal did not return a hosted approval URL.',
            );
        }
        foreach ($relations as $relation) {
            foreach ($links as $link) {
                if (
                    is_array($link)
                    && ($link['rel'] ?? null) === $relation
                    && is_string($link['href'] ?? null)
                    && $this->isPayPalUrl($link['href'])
                ) {
                    return $link['href'];
                }
            }
        }

        throw new BillingProviderException(
            'provider_invalid_response',
            'PayPal did not return a hosted approval URL.',
        );
    }

    private function isPayPalUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && str_ends_with(mb_strtolower($parts['host']), '.paypal.com');
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

    private function certificateUrl(string $url): string
    {
        $parts = parse_url($url);
        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || ! str_ends_with(mb_strtolower($parts['host']), '.paypal.com')
        ) {
            throw $this->invalidWebhook();
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $source */
    private function requiredString(array $source, string $key, int $maximumLength): string
    {
        $value = $source[$key] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > $maximumLength) {
            throw new BillingProviderException(
                'provider_invalid_response',
                'PayPal returned an incomplete billing response.',
            );
        }

        return $value;
    }

    /**
     *
     * @param array<string, mixed> $source
     *
     * @param list<string> $path
     */
    private function nestedString(array $source, array $path): ?string
    {
        $value = $source;
        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) && $value !== '' ? $value : null;
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
                'The PayPal ' . $label . ' is invalid.',
            );
        }
    }

    private function paypalAmount(int $amountMinor, string $currency): string
    {
        if (in_array($currency, ['HUF', 'JPY', 'TWD'], true)) {
            return (string) $amountMinor;
        }

        return number_format($amountMinor / 100, 2, '.', '');
    }

    private function invalidWebhook(): BillingProviderException
    {
        return new BillingProviderException(
            'provider_invalid_webhook',
            'The PayPal webhook is incomplete or invalid.',
        );
    }
}
