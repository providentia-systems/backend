<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Billing\Application\HostedCheckoutWebhook;
use Providentia\Billing\Infrastructure\Doctrine\DbalBillingStore;

final class BillingStoreTest extends TestCase
{
    private Connection $connection;
    private DbalBillingStore $store;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        foreach ($this->schema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->connection->insert('users', ['id' => 'user-1']);
        $this->connection->insert('homes', ['id' => 'home-1']);
        $this->store = new DbalBillingStore($this->connection);
        $this->now = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
    }

    public function testPlanPriceEntitlementPromotionAndOverrideRemainProviderNeutral(): void
    {
        $this->seedPlanAndPrice();
        $this->store->putProviderPriceReference(
            'price-1',
            'paypal',
            'paypal-price-1',
            $this->now,
        );
        $this->store->putEntitlement(
            'entitlement-1',
            'plan-1',
            'media.storage.bytes',
            '2147483648',
            'user-1',
            $this->now,
        );
        $this->store->createPromotion(
            'promotion-1',
            'EARLY20',
            'plan-1',
            'percent',
            2000,
            null,
            null,
            50,
            $this->now,
            $this->now->modify('+30 days'),
            'user-1',
            $this->now,
        );
        $this->store->createOverride(
            'override-1',
            'home-1',
            'media.storage.bytes',
            '4294967296',
            'Private pilot allocation',
            $this->now,
            $this->now->modify('+7 days'),
            'user-1',
            $this->now,
        );

        self::assertSame(
            'paypal-price-1',
            $this->store->providerPriceReference('price-1', 'paypal'),
        );
        self::assertSame(
            ['media.storage.bytes' => '2147483648'],
            $this->store->entitlements('plan-1'),
        );
        $promotion = $this->store->promotion('EARLY20');
        self::assertNotNull($promotion);
        self::assertSame('promotion-1', $promotion['id']);
        self::assertSame(
            ['media.storage.bytes' => '4294967296'],
            $this->store->activeOverrides('home-1', $this->now),
        );
    }

    public function testWebhookEventIsIdempotentAndAppliesSubscriptionExactlyOnce(): void
    {
        $this->seedPlanAndPrice();
        $this->store->createPromotion(
            'promotion-1',
            'EARLY20',
            'plan-1',
            'percent',
            2000,
            null,
            null,
            1,
            $this->now,
            $this->now->modify('+30 days'),
            'user-1',
            $this->now,
        );
        $this->store->createCheckoutSession(
            'checkout-1',
            'home-1',
            'price-1',
            'paypal',
            'provider-checkout-1',
            'https://payments.example/checkout/provider-checkout-1',
            'promotion-1',
            'user-1',
            $this->now->modify('+30 minutes'),
            $this->now,
        );
        $payloadHash = hash('sha256', '{"id":"event-1"}');
        self::assertSame('claimed', $this->store->claimWebhook(
            'paypal',
            'event-1',
            'checkout.completed',
            $payloadHash,
            $this->now,
            $this->now,
        ));
        $checkout = $this->store->checkoutByProviderReference('paypal', 'provider-checkout-1');
        self::assertNotNull($checkout);
        $event = new HostedCheckoutWebhook(
            'event-1',
            'checkout.completed',
            'provider-checkout-1',
            'provider-subscription-1',
            'provider-customer-1',
            'active',
            $this->now->modify('+1 month'),
            $this->now,
        );

        $this->store->applyWebhook($checkout, $event, $this->now);
        $this->store->markWebhookProcessed('paypal', 'event-1', $this->now);

        $subscription = $this->store->subscription('home-1');
        self::assertNotNull($subscription);
        self::assertSame('active', $subscription['status']);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT redemption_count FROM billing_promotion_codes WHERE id = :id',
            ['id' => 'promotion-1'],
        ));
        self::assertSame('duplicate', $this->store->claimWebhook(
            'paypal',
            'event-1',
            'checkout.completed',
            $payloadHash,
            $this->now,
            $this->now,
        ));
        self::assertSame('conflict', $this->store->claimWebhook(
            'paypal',
            'event-1',
            'checkout.completed',
            hash('sha256', 'different signed bytes'),
            $this->now,
            $this->now,
        ));
        $columns = $this->connection->fetchAllAssociative(
            'PRAGMA table_info(billing_webhook_events)',
        );
        $columnNames = [];
        foreach ($columns as $column) {
            $columnNames[] = (string) $column['name'];
        }
        $columnList = mb_strtolower(implode(' ', $columnNames));
        self::assertStringNotContainsString('payload_body', $columnList);
        self::assertStringNotContainsString('card', $columnList);
    }

    private function seedPlanAndPrice(): void
    {
        $this->store->createPlan(
            'plan-1',
            'household_plus',
            'Household Plus',
            'Shared pantry automation',
            'user-1',
            $this->now,
        );
        self::assertTrue($this->store->updatePlan(
            'plan-1',
            'Household Plus',
            'Shared pantry automation',
            'active',
            1,
            'user-1',
            $this->now,
        ));
        $this->store->createPrice(
            'price-1',
            'plan-1',
            'plus_nad_monthly',
            'NAD',
            9900,
            'month',
            1,
            14,
            'user-1',
            $this->now,
        );
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE users (id VARCHAR(36) PRIMARY KEY)',
            'CREATE TABLE homes (id VARCHAR(36) PRIMARY KEY)',
            'CREATE TABLE billing_plans (
                id VARCHAR(36) PRIMARY KEY, code VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL, description TEXT NOT NULL,
                status VARCHAR(24) NOT NULL, revision INTEGER NOT NULL,
                created_by_user_id VARCHAR(36) NOT NULL, updated_by_user_id VARCHAR(36) NOT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
            )',
            'CREATE TABLE billing_prices (
                id VARCHAR(36) PRIMARY KEY, plan_id VARCHAR(36) NOT NULL,
                code VARCHAR(64) NOT NULL UNIQUE, currency VARCHAR(3) NOT NULL,
                amount_minor BIGINT NOT NULL, interval_unit VARCHAR(12) NOT NULL,
                interval_count INTEGER NOT NULL, trial_days INTEGER NOT NULL,
                status VARCHAR(24) NOT NULL, revision INTEGER NOT NULL,
                created_by_user_id VARCHAR(36) NOT NULL, updated_by_user_id VARCHAR(36) NOT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                FOREIGN KEY (plan_id) REFERENCES billing_plans(id)
            )',
            'CREATE TABLE billing_provider_price_refs (
                price_id VARCHAR(36) NOT NULL, provider VARCHAR(32) NOT NULL,
                provider_reference VARCHAR(191) NOT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                PRIMARY KEY (price_id, provider), UNIQUE (provider, provider_reference),
                FOREIGN KEY (price_id) REFERENCES billing_prices(id)
            )',
            'CREATE TABLE billing_entitlements (
                id VARCHAR(36) PRIMARY KEY, plan_id VARCHAR(36) NOT NULL,
                feature_key VARCHAR(80) NOT NULL, value_json TEXT NOT NULL,
                updated_by_user_id VARCHAR(36) NOT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                UNIQUE (plan_id, feature_key),
                FOREIGN KEY (plan_id) REFERENCES billing_plans(id)
            )',
            'CREATE TABLE billing_promotion_codes (
                id VARCHAR(36) PRIMARY KEY, code VARCHAR(64) NOT NULL UNIQUE,
                plan_id VARCHAR(36) NULL, discount_type VARCHAR(16) NOT NULL,
                percent_off_bps INTEGER NULL, amount_off_minor BIGINT NULL,
                currency VARCHAR(3) NULL, maximum_redemptions INTEGER NULL,
                redemption_count INTEGER NOT NULL, valid_from DATETIME NOT NULL,
                valid_until DATETIME NULL, status VARCHAR(24) NOT NULL,
                created_by_user_id VARCHAR(36) NOT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
            )',
            'CREATE TABLE billing_home_entitlement_overrides (
                id VARCHAR(36) PRIMARY KEY, home_id VARCHAR(36) NOT NULL,
                feature_key VARCHAR(80) NOT NULL, value_json TEXT NOT NULL,
                reason VARCHAR(500) NOT NULL, valid_from DATETIME NOT NULL,
                valid_until DATETIME NULL, created_by_user_id VARCHAR(36) NOT NULL,
                revoked_by_user_id VARCHAR(36) NULL, revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL
            )',
            'CREATE TABLE billing_subscriptions (
                id VARCHAR(36) PRIMARY KEY, home_id VARCHAR(36) NOT NULL UNIQUE,
                plan_id VARCHAR(36) NOT NULL, price_id VARCHAR(36) NOT NULL,
                provider VARCHAR(32) NOT NULL, provider_customer_reference VARCHAR(191) NULL,
                provider_subscription_reference VARCHAR(191) NULL,
                status VARCHAR(24) NOT NULL, current_period_ends_at DATETIME NULL,
                cancel_at_period_end BOOLEAN NOT NULL, revision INTEGER NOT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
            )',
            'CREATE TABLE billing_checkout_sessions (
                id VARCHAR(36) PRIMARY KEY, home_id VARCHAR(36) NOT NULL,
                price_id VARCHAR(36) NOT NULL, promotion_id VARCHAR(36) NULL,
                provider VARCHAR(32) NOT NULL, provider_reference VARCHAR(191) NOT NULL,
                redirect_url VARCHAR(2048) NOT NULL, status VARCHAR(24) NOT NULL,
                created_by_user_id VARCHAR(36) NOT NULL, expires_at DATETIME NOT NULL,
                completed_at DATETIME NULL, created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL, UNIQUE (provider, provider_reference)
            )',
            'CREATE TABLE billing_webhook_events (
                provider VARCHAR(32) NOT NULL, provider_event_id VARCHAR(191) NOT NULL,
                event_type VARCHAR(64) NOT NULL, payload_sha256 VARCHAR(64) NOT NULL,
                status VARCHAR(24) NOT NULL, occurred_at DATETIME NOT NULL,
                received_at DATETIME NOT NULL, processed_at DATETIME NULL,
                PRIMARY KEY (provider, provider_event_id)
            )',
        ];
    }
}
