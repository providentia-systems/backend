<?php

declare(strict_types=1);

namespace Providentia\Billing;

use Providentia\Billing\Application\BillingAuthorization;
use Providentia\Billing\Application\BillingConfiguration;
use Providentia\Billing\Application\BillingHttpTransport;
use Providentia\Billing\Application\BillingService;
use Providentia\Billing\Application\BillingStore;
use Providentia\Billing\Application\CheckoutGatewayRegistry;
use Providentia\Billing\Application\HostedCardCheckoutGateway;
use Providentia\Billing\Application\PayPalHostedCheckoutGateway;
use Providentia\Billing\Infrastructure\Doctrine\DbalBillingStore;
use Providentia\Billing\Infrastructure\Factory\BillingFactory;
use Providentia\Billing\Infrastructure\Http\BillingEndpointPolicy;
use Providentia\Billing\Infrastructure\Http\StreamBillingHttpTransport;
use Providentia\Billing\Infrastructure\Provider\HostedCardRedirectAdapter;
use Providentia\Billing\Infrastructure\Provider\PayPalHostedCheckoutAdapter;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'billing' => [
                'enabled' => false,
                'allow_private_endpoints' => false,
                'http_timeout_seconds' => 10,
                'maximum_response_bytes' => 1048576,
                'providers' => [
                    'paypal' => [
                        'enabled' => false,
                        'service' => PayPalHostedCheckoutGateway::class,
                        'api_base' => 'https://api-m.sandbox.paypal.com',
                        'client_id' => '',
                        'client_secret' => '',
                        'webhook_id' => '',
                    ],
                    'hosted_card' => [
                        'enabled' => false,
                        'service' => HostedCardCheckoutGateway::class,
                        'api_base' => '',
                        'checkout_path' => '/v1/checkout/sessions',
                        'allowed_redirect_hosts' => [],
                        'api_key' => '',
                        'webhook_secret' => '',
                        'webhook_signature_header' => 'X-Webhook-Signature',
                        'webhook_timestamp_header' => 'X-Webhook-Timestamp',
                        'webhook_tolerance_seconds' => 300,
                    ],
                ],
            ],
            'dependencies' => [
                'aliases' => [
                    BillingStore::class => DbalBillingStore::class,
                    BillingHttpTransport::class => StreamBillingHttpTransport::class,
                    PayPalHostedCheckoutGateway::class => PayPalHostedCheckoutAdapter::class,
                    HostedCardCheckoutGateway::class => HostedCardRedirectAdapter::class,
                ],
                'factories' => [
                    DbalBillingStore::class => BillingFactory::class,
                    BillingEndpointPolicy::class => BillingFactory::class,
                    StreamBillingHttpTransport::class => BillingFactory::class,
                    PayPalHostedCheckoutAdapter::class => BillingFactory::class,
                    HostedCardRedirectAdapter::class => BillingFactory::class,
                    BillingAuthorization::class => BillingFactory::class,
                    BillingConfiguration::class => BillingFactory::class,
                    CheckoutGatewayRegistry::class => BillingFactory::class,
                    BillingService::class => BillingFactory::class,
                    'billing.plans.available' => BillingFactory::class,
                    'billing.operator.plans.list' => BillingFactory::class,
                    'billing.operator.plans.create' => BillingFactory::class,
                    'billing.operator.plans.update' => BillingFactory::class,
                    'billing.operator.prices.create' => BillingFactory::class,
                    'billing.operator.prices.status' => BillingFactory::class,
                    'billing.operator.provider-prices.put' => BillingFactory::class,
                    'billing.operator.entitlements.put' => BillingFactory::class,
                    'billing.operator.promotions.create' => BillingFactory::class,
                    'billing.operator.overrides.put' => BillingFactory::class,
                    'billing.operator.overrides.revoke' => BillingFactory::class,
                    'billing.home.summary' => BillingFactory::class,
                    'billing.checkout.create' => BillingFactory::class,
                    'billing.webhook' => BillingFactory::class,
                ],
            ],
        ];
    }
}
