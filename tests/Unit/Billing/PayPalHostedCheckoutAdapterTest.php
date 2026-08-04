<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Billing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Billing\Application\HostedCheckoutRequest;
use Providentia\Billing\Infrastructure\Provider\PayPalHostedCheckoutAdapter;

final class PayPalHostedCheckoutAdapterTest extends TestCase
{
    private DateTimeImmutable $now;
    private MockBillingHttpTransport $http;
    private PayPalHostedCheckoutAdapter $paypal;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $this->http = new MockBillingHttpTransport();
        $this->paypal = new PayPalHostedCheckoutAdapter(
            $this->http,
            new TestBillingClock($this->now),
            'https://api-m.sandbox.paypal.com',
            'client-id-fixture',
            'client-secret-fixture',
            'WEBHOOKIDFIXTURE',
            10,
            1048576,
        );
    }

    public function testCreatesOneTimeOrderUsingOAuthAndPayerActionLink(): void
    {
        $this->http->enqueueFixture(200, 'paypal/oauth-token.json');
        $this->http->enqueueFixture(201, 'paypal/order-created.json');

        $session = $this->paypal->createSession($this->request('one_time', null));

        self::assertSame('ORDER-FIXTURE-1', $session->providerReference);
        self::assertSame(
            'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-FIXTURE-1',
            $session->redirectUrl,
        );
        self::assertSame('2026-08-04T15:00:00+00:00', $session->expiresAt->format(DATE_ATOM));
        self::assertCount(2, $this->http->requests);
        self::assertSame(
            'https://api-m.sandbox.paypal.com/v1/oauth2/token',
            $this->http->requests[0]->url,
        );
        self::assertSame('grant_type=client_credentials', $this->http->requests[0]->body);
        self::assertSame(
            'https://api-m.sandbox.paypal.com/v2/checkout/orders',
            $this->http->requests[1]->url,
        );
        $payload = $this->object($this->http->requests[1]->body);
        self::assertSame('CAPTURE', $payload['intent']);
        $purchaseUnits = $payload['purchase_units'] ?? null;
        self::assertIsArray($purchaseUnits);
        $purchaseUnit = $this->objectValue($purchaseUnits[0] ?? null);
        $amount = $this->objectValue($purchaseUnit['amount'] ?? null);
        self::assertSame('12.34', $amount['value']);
        $paymentSource = $this->objectValue($payload['payment_source'] ?? null);
        $paypal = $this->objectValue($paymentSource['paypal'] ?? null);
        $experience = $this->objectValue($paypal['experience_context'] ?? null);
        self::assertSame('NO_SHIPPING', $experience['shipping_preference']);
        self::assertStringNotContainsString('card_number', $this->http->requests[1]->body);
        self::assertStringNotContainsString('cvc', $this->http->requests[1]->body);
        $this->http->assertExhausted();
    }

    public function testCreatesRecurringSubscriptionFromMappedPayPalPlan(): void
    {
        $this->http->enqueueFixture(200, 'paypal/oauth-token.json');
        $this->http->enqueueFixture(201, 'paypal/subscription-created.json');

        $session = $this->paypal->createSession($this->request('month', 'P-PLAN-FIXTURE-1'));

        self::assertSame('I-SUBSCRIPTION-FIXTURE-1', $session->providerReference);
        self::assertCount(2, $this->http->requests);
        self::assertSame(
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions',
            $this->http->requests[1]->url,
        );
        $payload = $this->object($this->http->requests[1]->body);
        self::assertSame('P-PLAN-FIXTURE-1', $payload['plan_id']);
        $applicationContext = $this->objectValue($payload['application_context'] ?? null);
        self::assertSame('SUBSCRIBE_NOW', $applicationContext['user_action']);
        self::assertSame(
            'checkout-fixture-1',
            $this->http->requests[1]->headers['PayPal-Request-Id'],
        );
        $this->http->assertExhausted();
    }

    public function testVerifiesWebhookWithPayPalBeforeNormalizingSubscription(): void
    {
        $this->http->enqueueFixture(200, 'paypal/oauth-token.json');
        $this->http->enqueueFixture(200, 'paypal/webhook-verified.json');
        $rawBody = $this->fixture('paypal/subscription-activated-webhook.json');

        $event = $this->paypal->verifyWebhook($rawBody, [
            'PAYPAL-AUTH-ALGO' => ['SHA256withRSA'],
            'PAYPAL-CERT-URL' => [
                'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-FIXTURE-1',
            ],
            'PAYPAL-TRANSMISSION-ID' => ['transmission-fixture-1'],
            'PAYPAL-TRANSMISSION-SIG' => ['signature-fixture-1'],
            'PAYPAL-TRANSMISSION-TIME' => ['2026-08-04T12:00:00Z'],
        ]);

        self::assertSame('WH-FIXTURE-1', $event->eventId);
        self::assertSame('checkout.completed', $event->eventType);
        self::assertSame('I-SUBSCRIPTION-FIXTURE-1', $event->checkoutReference);
        self::assertSame('PAYER-FIXTURE-1', $event->customerReference);
        self::assertSame('active', $event->subscriptionStatus);
        self::assertCount(2, $this->http->requests);
        self::assertSame(
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature',
            $this->http->requests[1]->url,
        );
        $verification = $this->object($this->http->requests[1]->body);
        self::assertSame('WEBHOOKIDFIXTURE', $verification['webhook_id']);
        $webhookEvent = $this->objectValue($verification['webhook_event'] ?? null);
        self::assertSame('WH-FIXTURE-1', $webhookEvent['id']);
        $this->http->assertExhausted();
    }

    public function testCapturesApprovedOneTimeOrderServerSide(): void
    {
        $this->http->enqueueFixture(200, 'paypal/oauth-token.json');
        $this->http->enqueueFixture(200, 'paypal/webhook-verified.json');
        $this->http->enqueueFixture(201, 'paypal/order-captured.json');
        $rawBody = $this->fixture('paypal/order-approved-webhook.json');

        $event = $this->paypal->verifyWebhook($rawBody, [
            'PAYPAL-AUTH-ALGO' => ['SHA256withRSA'],
            'PAYPAL-CERT-URL' => [
                'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-FIXTURE-1',
            ],
            'PAYPAL-TRANSMISSION-ID' => ['transmission-fixture-2'],
            'PAYPAL-TRANSMISSION-SIG' => ['signature-fixture-2'],
            'PAYPAL-TRANSMISSION-TIME' => ['2026-08-04T12:00:00Z'],
        ]);

        self::assertSame('checkout.completed', $event->eventType);
        self::assertSame('ORDER-FIXTURE-1', $event->checkoutReference);
        self::assertSame('active', $event->subscriptionStatus);
        self::assertCount(3, $this->http->requests);
        $capture = $this->http->requests[2];
        self::assertSame(
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-FIXTURE-1/capture',
            $capture->url,
        );
        self::assertSame('{}', $capture->body);
        self::assertSame(
            'WH-ORDER-APPROVED-FIXTURE-1-capture',
            $capture->headers['PayPal-Request-Id'],
        );
        $this->http->assertExhausted();
    }

    private function request(string $interval, ?string $providerReference): HostedCheckoutRequest
    {
        return new HostedCheckoutRequest(
            'checkout-fixture-1',
            'home-fixture-1',
            'price-fixture-1',
            'household_plus_usd',
            $providerReference,
            'USD',
            1234,
            $interval,
            1,
            'https://app.example/billing/success',
            'https://app.example/billing/cancel',
            null,
            ['media.storage.bytes' => 2147483648],
        );
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
