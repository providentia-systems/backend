<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804001200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Provider-neutral plans, entitlements, hosted checkout and idempotent billing state';
    }

    public function up(Schema $schema): void
    {
        $plans = $schema->createTable('billing_plans');
        $plans->addColumn('id', Types::STRING, ['length' => 36]);
        $plans->addColumn('code', Types::STRING, ['length' => 64]);
        $plans->addColumn('name', Types::STRING, ['length' => 120]);
        $plans->addColumn('description', Types::TEXT);
        $plans->addColumn('status', Types::STRING, ['length' => 24]);
        $plans->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $plans->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $plans->addColumn('updated_by_user_id', Types::STRING, ['length' => 36]);
        $plans->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $plans->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $plans->setPrimaryKey(['id']);
        $plans->addUniqueIndex(['code'], 'uniq_billing_plan_code');
        $plans->addIndex(['status', 'code'], 'idx_billing_plan_catalog');
        $plans->addForeignKeyConstraint('users', ['created_by_user_id'], ['id']);
        $plans->addForeignKeyConstraint('users', ['updated_by_user_id'], ['id']);

        $prices = $schema->createTable('billing_prices');
        $prices->addColumn('id', Types::STRING, ['length' => 36]);
        $prices->addColumn('plan_id', Types::STRING, ['length' => 36]);
        $prices->addColumn('code', Types::STRING, ['length' => 64]);
        $prices->addColumn('currency', Types::STRING, ['length' => 3]);
        $prices->addColumn('amount_minor', Types::BIGINT);
        $prices->addColumn('interval_unit', Types::STRING, ['length' => 12]);
        $prices->addColumn('interval_count', Types::INTEGER);
        $prices->addColumn('trial_days', Types::INTEGER, ['default' => 0]);
        $prices->addColumn('status', Types::STRING, ['length' => 24]);
        $prices->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $prices->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $prices->addColumn('updated_by_user_id', Types::STRING, ['length' => 36]);
        $prices->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $prices->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $prices->setPrimaryKey(['id']);
        $prices->addUniqueIndex(['code'], 'uniq_billing_price_code');
        $prices->addIndex(
            ['plan_id', 'status', 'currency', 'amount_minor'],
            'idx_billing_price_catalog',
        );
        $prices->addForeignKeyConstraint('billing_plans', ['plan_id'], ['id']);
        $prices->addForeignKeyConstraint('users', ['created_by_user_id'], ['id']);
        $prices->addForeignKeyConstraint('users', ['updated_by_user_id'], ['id']);

        $providerRefs = $schema->createTable('billing_provider_price_refs');
        $providerRefs->addColumn('price_id', Types::STRING, ['length' => 36]);
        $providerRefs->addColumn('provider', Types::STRING, ['length' => 32]);
        $providerRefs->addColumn('provider_reference', Types::STRING, ['length' => 191]);
        $providerRefs->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $providerRefs->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $providerRefs->setPrimaryKey(['price_id', 'provider']);
        $providerRefs->addUniqueIndex(
            ['provider', 'provider_reference'],
            'uniq_billing_provider_price_ref',
        );
        $providerRefs->addForeignKeyConstraint(
            'billing_prices',
            ['price_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );

        $entitlements = $schema->createTable('billing_entitlements');
        $entitlements->addColumn('id', Types::STRING, ['length' => 36]);
        $entitlements->addColumn('plan_id', Types::STRING, ['length' => 36]);
        $entitlements->addColumn('feature_key', Types::STRING, ['length' => 80]);
        $entitlements->addColumn('value_json', Types::TEXT);
        $entitlements->addColumn('updated_by_user_id', Types::STRING, ['length' => 36]);
        $entitlements->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $entitlements->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $entitlements->setPrimaryKey(['id']);
        $entitlements->addUniqueIndex(
            ['plan_id', 'feature_key'],
            'uniq_billing_plan_entitlement',
        );
        $entitlements->addForeignKeyConstraint(
            'billing_plans',
            ['plan_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );
        $entitlements->addForeignKeyConstraint('users', ['updated_by_user_id'], ['id']);

        $promotions = $schema->createTable('billing_promotion_codes');
        $promotions->addColumn('id', Types::STRING, ['length' => 36]);
        $promotions->addColumn('code', Types::STRING, ['length' => 64]);
        $promotions->addColumn('plan_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $promotions->addColumn('discount_type', Types::STRING, ['length' => 16]);
        $promotions->addColumn('percent_off_bps', Types::INTEGER, ['notnull' => false]);
        $promotions->addColumn('amount_off_minor', Types::BIGINT, ['notnull' => false]);
        $promotions->addColumn('currency', Types::STRING, ['length' => 3, 'notnull' => false]);
        $promotions->addColumn('maximum_redemptions', Types::INTEGER, ['notnull' => false]);
        $promotions->addColumn('redemption_count', Types::INTEGER, ['default' => 0]);
        $promotions->addColumn('valid_from', Types::DATETIME_IMMUTABLE);
        $promotions->addColumn('valid_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $promotions->addColumn('status', Types::STRING, ['length' => 24]);
        $promotions->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $promotions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $promotions->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $promotions->setPrimaryKey(['id']);
        $promotions->addUniqueIndex(['code'], 'uniq_billing_promotion_code');
        $promotions->addIndex(
            ['status', 'valid_from', 'valid_until'],
            'idx_billing_promotion_validity',
        );
        $promotions->addForeignKeyConstraint('billing_plans', ['plan_id'], ['id']);
        $promotions->addForeignKeyConstraint('users', ['created_by_user_id'], ['id']);

        $overrides = $schema->createTable('billing_home_entitlement_overrides');
        $overrides->addColumn('id', Types::STRING, ['length' => 36]);
        $overrides->addColumn('home_id', Types::STRING, ['length' => 36]);
        $overrides->addColumn('feature_key', Types::STRING, ['length' => 80]);
        $overrides->addColumn('value_json', Types::TEXT);
        $overrides->addColumn('reason', Types::STRING, ['length' => 500]);
        $overrides->addColumn('valid_from', Types::DATETIME_IMMUTABLE);
        $overrides->addColumn('valid_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $overrides->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $overrides->addColumn('revoked_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $overrides->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $overrides->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $overrides->setPrimaryKey(['id']);
        $overrides->addIndex(
            ['home_id', 'feature_key', 'valid_from', 'valid_until', 'revoked_at'],
            'idx_billing_home_override_active',
        );
        $overrides->addForeignKeyConstraint(
            'homes',
            ['home_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );
        $overrides->addForeignKeyConstraint('users', ['created_by_user_id'], ['id']);
        $overrides->addForeignKeyConstraint('users', ['revoked_by_user_id'], ['id']);

        $subscriptions = $schema->createTable('billing_subscriptions');
        $subscriptions->addColumn('id', Types::STRING, ['length' => 36]);
        $subscriptions->addColumn('home_id', Types::STRING, ['length' => 36]);
        $subscriptions->addColumn('plan_id', Types::STRING, ['length' => 36]);
        $subscriptions->addColumn('price_id', Types::STRING, ['length' => 36]);
        $subscriptions->addColumn('provider', Types::STRING, ['length' => 32]);
        $subscriptions->addColumn('provider_customer_reference', Types::STRING, [
            'length' => 191,
            'notnull' => false,
        ]);
        $subscriptions->addColumn('provider_subscription_reference', Types::STRING, [
            'length' => 191,
            'notnull' => false,
        ]);
        $subscriptions->addColumn('status', Types::STRING, ['length' => 24]);
        $subscriptions->addColumn('current_period_ends_at', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
        ]);
        $subscriptions->addColumn('cancel_at_period_end', Types::BOOLEAN, ['default' => false]);
        $subscriptions->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $subscriptions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $subscriptions->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $subscriptions->setPrimaryKey(['id']);
        $subscriptions->addUniqueIndex(['home_id'], 'uniq_billing_home_subscription');
        $subscriptions->addUniqueIndex(
            ['provider', 'provider_subscription_reference'],
            'uniq_billing_provider_subscription',
        );
        $subscriptions->addIndex(['status', 'updated_at'], 'idx_billing_subscription_status');
        $subscriptions->addForeignKeyConstraint(
            'homes',
            ['home_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );
        $subscriptions->addForeignKeyConstraint('billing_plans', ['plan_id'], ['id']);
        $subscriptions->addForeignKeyConstraint('billing_prices', ['price_id'], ['id']);

        $checkouts = $schema->createTable('billing_checkout_sessions');
        $checkouts->addColumn('id', Types::STRING, ['length' => 36]);
        $checkouts->addColumn('home_id', Types::STRING, ['length' => 36]);
        $checkouts->addColumn('price_id', Types::STRING, ['length' => 36]);
        $checkouts->addColumn('promotion_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $checkouts->addColumn('provider', Types::STRING, ['length' => 32]);
        $checkouts->addColumn('provider_reference', Types::STRING, ['length' => 191]);
        $checkouts->addColumn('redirect_url', Types::STRING, ['length' => 2048]);
        $checkouts->addColumn('status', Types::STRING, ['length' => 24]);
        $checkouts->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $checkouts->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $checkouts->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $checkouts->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $checkouts->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $checkouts->setPrimaryKey(['id']);
        $checkouts->addUniqueIndex(
            ['provider', 'provider_reference'],
            'uniq_billing_provider_checkout',
        );
        $checkouts->addIndex(
            ['home_id', 'status', 'created_at'],
            'idx_billing_checkout_home',
        );
        $checkouts->addForeignKeyConstraint(
            'homes',
            ['home_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );
        $checkouts->addForeignKeyConstraint('billing_prices', ['price_id'], ['id']);
        $checkouts->addForeignKeyConstraint('billing_promotion_codes', ['promotion_id'], ['id']);
        $checkouts->addForeignKeyConstraint('users', ['created_by_user_id'], ['id']);

        $webhooks = $schema->createTable('billing_webhook_events');
        $webhooks->addColumn('provider', Types::STRING, ['length' => 32]);
        $webhooks->addColumn('provider_event_id', Types::STRING, ['length' => 191]);
        $webhooks->addColumn('event_type', Types::STRING, ['length' => 64]);
        $webhooks->addColumn('payload_sha256', Types::STRING, ['length' => 64]);
        $webhooks->addColumn('status', Types::STRING, ['length' => 24]);
        $webhooks->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $webhooks->addColumn('received_at', Types::DATETIME_IMMUTABLE);
        $webhooks->addColumn('processed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $webhooks->setPrimaryKey(['provider', 'provider_event_id']);
        $webhooks->addIndex(['status', 'received_at'], 'idx_billing_webhook_processing');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('billing_webhook_events');
        $schema->dropTable('billing_checkout_sessions');
        $schema->dropTable('billing_subscriptions');
        $schema->dropTable('billing_home_entitlement_overrides');
        $schema->dropTable('billing_promotion_codes');
        $schema->dropTable('billing_entitlements');
        $schema->dropTable('billing_provider_price_refs');
        $schema->dropTable('billing_prices');
        $schema->dropTable('billing_plans');
    }
}
