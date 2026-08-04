<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Billing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Billing\Application\BillingProviderException;
use Providentia\Billing\Application\HostedCheckoutRequest;
use Providentia\Billing\Infrastructure\Provider\HostedCardRedirectAdapter;

final class HostedCardRedirectAdapterTest extends TestCase
{
    private const WEBHOOK_SECRET = 'fixture-webhook-secret-at-least-32-bytes';

    private DateTimeImmutable $now;
    private MockBillingHttpTransport $http;
    private HostedCardRedirectAdapter $adapter;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $this->http = new MockBillingHttpTransport();
        $this->adapter = new HostedCardRedirectAdapter(
            $this->http,
            new TestBillingClock($this->now),
            'https://card-api.example',
            '/v1/checkout/sessions',
            ['secure-card.example'],
            'server-api-key-fixture',
            self::WEBHOOK_SECRET,
            'X-Webhook-Signature',
            'X-Webhook-Timestamp',
            300,
            10,
            1048576,
        );
    }

    public function testCreatesRedirectSessionWithoutSendingCardOrHouseholdData(): void
    {
        $this->http->enqueueFixture(201, 'hosted-card/checkout-created.json');
        $request = new HostedCheckoutRequest(
            'checkout-fixture-1',
            'home-private-fixture-1',
            'price-fixture-1',
            'household_plus_usd',
            'provider-price-fixture-1',
            'USD',
            1234,
            'month',
            1,
            'https://app.example/billing/success',
            'https://app.example/billing/cancel',
            'EARLY20',
            ['media.storage.bytes' => 2147483648],
        );

        $session = $this->adapter->createSession($request);

        self::assertSame('card-session-fixture-1', $session->providerReference);
        self::assertSame(
            'https://secure-card.example/checkout/card-session-fixture-1',
            $session->redirectUrl,
        );
        self::assertCount(1, $this->http->requests);
        $sent = $this->http->requests[0];
        self::assertSame('https://card-api.example/v1/checkout/sessions', $sent->url);
        self::assertSame('Bearer server-api-key-fixture', $sent->headers['Authorization']);
        self::assertSame('checkout-fixture-1', $sent->headers['Idempotency-Key']);
        self::assertStringNotContainsString('home-private-fixture-1', $sent->body);
        self::assertStringNotContainsString('card_number', $sent->body);
        self::assertStringNotContainsString('pan', mb_strtolower($sent->body));
        self::assertStringNotContainsString('cvc', mb_strtolower($sent->body));
        $payload = $this->object($sent->body);
        $price = $this->objectValue($payload['price'] ?? null);
        self::assertSame(1234, $price['amount_minor']);
        self::assertSame('EARLY20', $payload['promotion_code']);
        $this->http->assertExhausted();
    }

    public function testVerifiesTimestampedHmacBeforeNormalizingWebhook(): void
    {
        $body = $this->fixture('hosted-card/subscription-activated-webhook.json');
        $timestamp = (string) $this->now->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, self::WEBHOOK_SECRET);

        $event = $this->adapter->verifyWebhook($body, [
            'X-Webhook-Timestamp' => [$timestamp],
            'X-Webhook-Signature' => ['v1=' . $signature],
        ]);

        self::assertSame('card-event-fixture-1', $event->eventId);
        self::assertSame('checkout.completed', $event->eventType);
        self::assertSame('card-session-fixture-1', $event->checkoutReference);
        self::assertSame('card-subscription-fixture-1', $event->subscriptionReference);
        self::assertSame('active', $event->subscriptionStatus);
    }

    public function testRejectsInvalidWebhookSignature(): void
    {
        $body = $this->fixture('hosted-card/subscription-activated-webhook.json');

        try {
            $this->adapter->verifyWebhook($body, [
                'X-Webhook-Timestamp' => [(string) $this->now->getTimestamp()],
                'X-Webhook-Signature' => [str_repeat('0', 64)],
            ]);
            self::fail('An invalid hosted-card webhook signature was accepted.');
        } catch (BillingProviderException $error) {
            self::assertSame('provider_webhook_signature_invalid', $error->safeCode);
        }
    }

    /** @return array<string, mixed> */
    private function object(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));

        return $value;
    }

    /** @return array<string, mixed> */
    private function objectValue(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));

        return $value;
    }

    private function fixture(string $name): string
    {
        $value = file_get_contents(dirname(__DIR__, 2) . '/fixtures/billing/' . $name);
        self::assertIsString($value);

        return $value;
    }
}
