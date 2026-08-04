<?php

declare(strict_types=1);

namespace Providentia\Billing\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Billing\Application\BillingAuthorization;
use Providentia\Billing\Application\BillingConfiguration;
use Providentia\Billing\Application\BillingHttpTransport;
use Providentia\Billing\Application\BillingService;
use Providentia\Billing\Application\BillingStore;
use Providentia\Billing\Application\CheckoutGatewayRegistry;
use Providentia\Billing\Application\HostedCheckoutGateway;
use Providentia\Billing\Http\BillingHandler;
use Providentia\Billing\Http\BillingWebhookHandler;
use Providentia\Billing\Infrastructure\Doctrine\DbalBillingStore;
use Providentia\Billing\Infrastructure\Http\BillingEndpointPolicy;
use Providentia\Billing\Infrastructure\Http\StreamBillingHttpTransport;
use Providentia\Billing\Infrastructure\Provider\HostedCardRedirectAdapter;
use Providentia\Billing\Infrastructure\Provider\PayPalHostedCheckoutAdapter;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class BillingFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /** @var array<string, mixed> $config */
        $config = $container->get('config');
        /** @var array<string, mixed> $billing */
        $billing = $config['billing'];

        return match (true) {
            $requestedName === DbalBillingStore::class => new DbalBillingStore(
                $container->get(Connection::class),
            ),
            $requestedName === BillingEndpointPolicy::class => new BillingEndpointPolicy(
                $this->allowedHosts($billing),
                (bool) ($billing['allow_private_endpoints'] ?? false),
            ),
            $requestedName === StreamBillingHttpTransport::class => new StreamBillingHttpTransport(
                $container->get(BillingEndpointPolicy::class),
            ),
            $requestedName === PayPalHostedCheckoutAdapter::class => $this->paypal(
                $container,
                $billing,
            ),
            $requestedName === HostedCardRedirectAdapter::class => $this->hostedCard(
                $container,
                $billing,
            ),
            $requestedName === BillingAuthorization::class => new BillingAuthorization(
                $container->get(HomeAuthorization::class),
            ),
            $requestedName === BillingConfiguration::class => new BillingConfiguration(
                (bool) ($billing['enabled'] ?? false),
                $this->enabledProviderNames($billing),
            ),
            $requestedName === CheckoutGatewayRegistry::class => new CheckoutGatewayRegistry(
                $this->gateways($container, $billing),
            ),
            $requestedName === BillingService::class => new BillingService(
                $container->get(BillingStore::class),
                $container->get(BillingAuthorization::class),
                $container->get(CheckoutGatewayRegistry::class),
                $container->get(BillingConfiguration::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            $requestedName === 'billing.webhook' => new BillingWebhookHandler(
                $container->get(BillingService::class),
            ),
            str_starts_with($requestedName, 'billing.') => new BillingHandler(
                $container->get(BillingService::class),
                substr($requestedName, strlen('billing.')),
            ),
            default => throw new \LogicException('Unsupported billing service: ' . $requestedName),
        };
    }

    /**
     * @param array<string, mixed> $billing
     * @return list<string>
     */
    private function enabledProviderNames(array $billing): array
    {
        $enabled = [];
        foreach ($this->providerConfiguration($billing) as $name => $provider) {
            if (($provider['enabled'] ?? false) === true) {
                $enabled[] = $name;
            }
        }

        return $enabled;
    }

    /**
     * @param array<string, mixed> $billing
     * @return list<HostedCheckoutGateway>
     */
    private function gateways(ContainerInterface $container, array $billing): array
    {
        $gateways = [];
        foreach ($this->providerConfiguration($billing) as $provider) {
            if (($provider['enabled'] ?? false) !== true) {
                continue;
            }
            $service = $provider['service'] ?? null;
            if (! is_string($service) || $service === '' || ! $container->has($service)) {
                throw new \RuntimeException('Enabled billing provider service is unavailable.');
            }
            $gateway = $container->get($service);
            if (! $gateway instanceof HostedCheckoutGateway) {
                throw new \RuntimeException('Billing provider does not implement hosted checkout.');
            }
            $gateways[] = $gateway;
        }

        return $gateways;
    }

    /**
     * @param array<string, mixed> $billing
     * @return array<string, array<string, mixed>>
     */
    private function providerConfiguration(array $billing): array
    {
        $providers = $billing['providers'] ?? [];
        if (! is_array($providers)) {
            return [];
        }
        $result = [];
        foreach ($providers as $name => $provider) {
            if (
                is_string($name)
                && in_array($name, ['paypal', 'hosted_card'], true)
                && is_array($provider)
            ) {
                $result[$name] = $provider;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $billing */
    private function paypal(ContainerInterface $container, array $billing): PayPalHostedCheckoutAdapter
    {
        $provider = $this->providerConfiguration($billing)['paypal'] ?? [];

        return new PayPalHostedCheckoutAdapter(
            $container->get(BillingHttpTransport::class),
            $container->get(Clock::class),
            (string) ($provider['api_base'] ?? ''),
            (string) ($provider['client_id'] ?? ''),
            (string) ($provider['client_secret'] ?? ''),
            (string) ($provider['webhook_id'] ?? ''),
            (int) ($billing['http_timeout_seconds'] ?? 10),
            (int) ($billing['maximum_response_bytes'] ?? 1048576),
        );
    }

    /** @param array<string, mixed> $billing */
    private function hostedCard(
        ContainerInterface $container,
        array $billing,
    ): HostedCardRedirectAdapter {
        $provider = $this->providerConfiguration($billing)['hosted_card'] ?? [];

        return new HostedCardRedirectAdapter(
            $container->get(BillingHttpTransport::class),
            $container->get(Clock::class),
            (string) ($provider['api_base'] ?? ''),
            (string) ($provider['checkout_path'] ?? '/v1/checkout/sessions'),
            $this->stringList($provider['allowed_redirect_hosts'] ?? []),
            (string) ($provider['api_key'] ?? ''),
            (string) ($provider['webhook_secret'] ?? ''),
            (string) ($provider['webhook_signature_header'] ?? 'X-Webhook-Signature'),
            (string) ($provider['webhook_timestamp_header'] ?? 'X-Webhook-Timestamp'),
            (int) ($provider['webhook_tolerance_seconds'] ?? 300),
            (int) ($billing['http_timeout_seconds'] ?? 10),
            (int) ($billing['maximum_response_bytes'] ?? 1048576),
        );
    }

    /**
     * @param array<string, mixed> $billing
     * @return list<string>
     */
    private function allowedHosts(array $billing): array
    {
        $hosts = [];
        foreach ($this->providerConfiguration($billing) as $provider) {
            $base = $provider['api_base'] ?? null;
            if (! is_string($base) || $base === '') {
                continue;
            }
            $host = parse_url($base, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = mb_strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $candidate) {
            if (is_string($candidate)) {
                $strings[] = $candidate;
            }
        }

        return $strings;
    }
}
