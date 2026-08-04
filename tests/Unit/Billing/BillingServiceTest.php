<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Billing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Billing\Application\BillingAuthorization;
use Providentia\Billing\Application\BillingConfiguration;
use Providentia\Billing\Application\BillingProviderException;
use Providentia\Billing\Application\BillingService;
use Providentia\Billing\Application\BillingStore;
use Providentia\Billing\Application\CheckoutGatewayRegistry;
use Providentia\Billing\Application\HostedCheckoutRequest;
use Providentia\Billing\Application\HostedCheckoutSession;
use Providentia\Billing\Application\HostedCheckoutWebhook;
use Providentia\Billing\Application\PayPalHostedCheckoutGateway;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeStore;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class BillingServiceTest extends TestCase
{
    public const NOW = '2026-08-04T12:00:00+00:00';

    public function testBillingIsDisabledUntilDeploymentExplicitlyEnablesIt(): void
    {
        $service = $this->service(
            $this->createStub(BillingStore::class),
            new FakePayPalGateway(),
            false,
        );

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('disabled');
        $service->availablePlans();
    }

    public function testOperatorCanCreateAPlanWhileRuntimeBillingIsDisabled(): void
    {
        $store = $this->createMock(BillingStore::class);
        $store->expects(self::once())->method('createPlan')->with(
            'generated-id',
            'household_plus',
            'Household Plus',
            'Shared pantry automation',
            'operator-user',
            self::isInstanceOf(DateTimeImmutable::class),
        );
        $service = $this->service($store, new FakePayPalGateway(), false);

        $result = $service->createPlan(
            $this->identity(['billing_operator'], 'operator-user'),
            ' Household_Plus ',
            'Household Plus',
            'Shared pantry automation',
        );

        self::assertSame('generated-id', $result['id']);
        self::assertSame('household_plus', $result['code']);
        self::assertSame('draft', $result['status']);
    }

    public function testCheckoutSendsOnlyProviderNeutralHostedPaymentData(): void
    {
        $store = $this->createMock(BillingStore::class);
        $store->method('price')->with('price-1')->willReturn([
            'id' => 'price-1',
            'planId' => 'plan-1',
            'code' => 'plus_nad_monthly',
            'currency' => 'NAD',
            'amountMinor' => 9900,
            'intervalUnit' => 'month',
            'intervalCount' => 1,
            'status' => 'active',
            'planStatus' => 'active',
        ]);
        $store->method('providerPriceReference')->willReturn('paypal-price-1');
        $store->method('entitlements')->with('plan-1')->willReturn([
            'media.storage.bytes' => '2147483648',
            'ai.enabled' => 'true',
        ]);
        $store->expects(self::once())->method('createCheckoutSession')->with(
            'generated-id',
            'home-1',
            'price-1',
            'paypal',
            'provider-session-1',
            'https://payments.example/checkout/provider-session-1',
            null,
            'owner-user',
            self::isInstanceOf(DateTimeImmutable::class),
            self::isInstanceOf(DateTimeImmutable::class),
        );
        $gateway = new FakePayPalGateway();
        $service = $this->service($store, $gateway, true, 'owner');

        $result = $service->startCheckout(
            $this->identity([], 'owner-user'),
            'home-1',
            'price-1',
            'paypal',
            'https://app.example/billing/success',
            'https://app.example/billing/cancel',
            null,
        );

        self::assertSame('https://payments.example/checkout/provider-session-1', $result['redirectUrl']);
        $gatewayRequest = $gateway->request;
        self::assertInstanceOf(HostedCheckoutRequest::class, $gatewayRequest);
        self::assertSame('paypal-price-1', $gatewayRequest->providerPriceReference);
        self::assertSame(2147483648, $gatewayRequest->entitlements['media.storage.bytes']);
        self::assertObjectNotHasProperty('cardNumber', $gatewayRequest);
        self::assertObjectNotHasProperty('cvc', $gatewayRequest);
    }

    public function testVerifiedWebhookReplayIsAcknowledgedWithoutApplyingItTwice(): void
    {
        $store = $this->createMock(BillingStore::class);
        $store->expects(self::once())->method('claimWebhook')->willReturn('duplicate');
        $store->expects(self::never())->method('checkoutByProviderReference');
        $store->expects(self::never())->method('applyWebhook');
        $gateway = new FakePayPalGateway();
        $service = $this->service($store, $gateway, true);

        $result = $service->acceptWebhook(
            'paypal',
            '{"id":"event-1"}',
            ['PayPal-Transmission-Sig' => ['verified-by-adapter']],
        );

        self::assertSame('duplicate', $result);
        self::assertSame('{"id":"event-1"}', $gateway->webhookBody);
    }

    public function testApprovedPayPalWebhookIsClaimedBeforeServerSideCapture(): void
    {
        $claimed = false;
        $store = $this->createMock(BillingStore::class);
        $store->expects(self::once())->method('claimWebhook')->willReturnCallback(
            static function () use (&$claimed): string {
                $claimed = true;

                return 'claimed';
            },
        );
        $store->method('checkoutByProviderReference')->willReturn([
            'id' => 'checkout-1',
            'homeId' => 'home-1',
            'planId' => 'plan-1',
            'priceId' => 'price-1',
            'promotionId' => null,
            'provider' => 'paypal',
        ]);
        $store->expects(self::once())->method('applyWebhook');
        $store->expects(self::once())->method('markWebhookProcessed');
        $store->expects(self::never())->method('releaseWebhookClaim');
        $gateway = new FakePayPalGateway();
        $gateway->webhookEventType = 'checkout.approved';
        $gateway->onCapture = static function () use (&$claimed): void {
            self::assertTrue($claimed, 'PayPal capture ran before the signed event was claimed.');
        };

        $result = $this->service($store, $gateway, true)->acceptWebhook(
            'paypal',
            '{"id":"event-1"}',
            ['PayPal-Transmission-Sig' => ['verified-by-adapter']],
        );

        self::assertSame('processed', $result);
        self::assertSame(1, $gateway->captureCalls);
    }

    public function testFailedCaptureReleasesTheClaimForProviderRetry(): void
    {
        $store = $this->createMock(BillingStore::class);
        $store->expects(self::once())->method('claimWebhook')->willReturn('claimed');
        $store->expects(self::once())->method('releaseWebhookClaim');
        $store->expects(self::never())->method('applyWebhook');
        $gateway = new FakePayPalGateway();
        $gateway->webhookEventType = 'checkout.approved';
        $gateway->failCapture = true;

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('should be retried');
        $this->service($store, $gateway, true)->acceptWebhook(
            'paypal',
            '{"id":"event-1"}',
            ['PayPal-Transmission-Sig' => ['verified-by-adapter']],
        );
    }

    public function testHomeSummaryCombinesPlanEntitlementsWithActiveOperatorOverrides(): void
    {
        $store = $this->createStub(BillingStore::class);
        $store->method('subscription')->willReturn([
            'id' => 'subscription-1',
            'planId' => 'plan-1',
            'status' => 'active',
        ]);
        $store->method('entitlements')->willReturn([
            'ai.enabled' => 'true',
            'media.storage.bytes' => '2147483648',
        ]);
        $store->method('activeOverrides')->willReturn([
            'media.storage.bytes' => '4294967296',
        ]);
        $service = $this->service($store, new FakePayPalGateway(), true, 'manager');

        $summary = $service->homeSummary($this->identity([], 'manager-user'), 'home-1');

        self::assertTrue($summary['entitlements']['ai.enabled']);
        self::assertSame(4294967296, $summary['entitlements']['media.storage.bytes']);
    }

    /** @param list<string> $roles */
    private function identity(array $roles = [], string $userId = 'user-1'): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity($userId, 'session-1', 'device-1', null, $roles);
    }

    private function service(
        BillingStore $store,
        FakePayPalGateway $gateway,
        bool $enabled,
        string $homeRole = 'owner',
    ): BillingService {
        $homes = $this->createStub(HomeStore::class);
        $homes->method('membership')->willReturn(['status' => 'active', 'role' => $homeRole]);

        return new BillingService(
            $store,
            new BillingAuthorization(new HomeAuthorization($homes)),
            new CheckoutGatewayRegistry([$gateway]),
            new BillingConfiguration($enabled, ['paypal']),
            new FixedBillingUuidGenerator(),
            new FixedBillingClock(),
            new ImmediateBillingTransactionManager(),
        );
    }
}

final class FakePayPalGateway implements PayPalHostedCheckoutGateway
{
    public ?HostedCheckoutRequest $request = null;
    public ?string $webhookBody = null;
    public string $webhookEventType = 'checkout.completed';
    public int $captureCalls = 0;
    public bool $failCapture = false;
    public ?\Closure $onCapture = null;

    public function provider(): string
    {
        return 'paypal';
    }

    public function createSession(HostedCheckoutRequest $request): HostedCheckoutSession
    {
        $this->request = $request;

        return new HostedCheckoutSession(
            'provider-session-1',
            'https://payments.example/checkout/provider-session-1',
            new DateTimeImmutable('2026-08-04T12:30:00+00:00'),
        );
    }

    /** @param array<string, list<string>> $headers */
    public function verifyWebhook(string $rawBody, array $headers): HostedCheckoutWebhook
    {
        unset($headers);
        $this->webhookBody = $rawBody;

        return new HostedCheckoutWebhook(
            'event-1',
            $this->webhookEventType,
            'provider-session-1',
            'subscription-1',
            'customer-1',
            'active',
            new DateTimeImmutable('2026-09-04T12:00:00+00:00'),
            new DateTimeImmutable(BillingServiceTest::NOW),
        );
    }

    public function captureApprovedOrder(HostedCheckoutWebhook $approved): HostedCheckoutWebhook
    {
        ++$this->captureCalls;
        if ($this->onCapture !== null) {
            ($this->onCapture)();
        }
        if ($this->failCapture) {
            throw new BillingProviderException('capture_failed', 'The capture failed safely.');
        }

        return new HostedCheckoutWebhook(
            $approved->eventId,
            'checkout.completed',
            $approved->checkoutReference,
            null,
            $approved->customerReference,
            'active',
            null,
            $approved->occurredAt,
        );
    }
}

final class FixedBillingUuidGenerator implements UuidGenerator
{
    public function generate(): string
    {
        return 'generated-id';
    }
}

final class FixedBillingClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(BillingServiceTest::NOW);
    }
}

final class ImmediateBillingTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
