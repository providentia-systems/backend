<?php

declare(strict_types=1);

namespace Providentia\Billing\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Providentia\Billing\Application\BillingStore;
use Providentia\Billing\Application\HostedCheckoutWebhook;
use Providentia\Billing\Application\OperatorSubscriptionReader;

final readonly class DbalBillingStore implements BillingStore, OperatorSubscriptionReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function homeExists(string $homeId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM homes WHERE id = :id',
            ['id' => $homeId],
        ) === 1;
    }

    public function plans(bool $includeInactive): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, code, name, description, status, revision,
                    created_by_user_id AS createdByUserId,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM billing_plans
             WHERE (:include_inactive = 1 OR status = :active)
             ORDER BY code, id',
            ['include_inactive' => $includeInactive ? 1 : 0, 'active' => 'active'],
        );
    }

    public function plan(string $planId): ?array
    {
        return $this->one(
            'SELECT id, code, name, description, status, revision,
                    created_by_user_id AS createdByUserId,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM billing_plans WHERE id = :id',
            ['id' => $planId],
        );
    }

    public function createPlan(
        string $id,
        string $code,
        string $name,
        string $description,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $this->connection->insert('billing_plans', [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'status' => 'draft',
            'revision' => 1,
            'created_by_user_id' => $actorUserId,
            'updated_by_user_id' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updatePlan(
        string $planId,
        string $name,
        string $description,
        string $status,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE billing_plans
             SET name = :name, description = :description, status = :status,
                 revision = revision + 1, updated_by_user_id = :actor,
                 updated_at = :updated
             WHERE id = :id AND revision = :revision',
            [
                'name' => $name,
                'description' => $description,
                'status' => $status,
                'actor' => $actorUserId,
                'updated' => $this->date($at),
                'id' => $planId,
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function prices(string $planId, bool $includeInactive): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, plan_id AS planId, code, currency,
                    amount_minor AS amountMinor, interval_unit AS intervalUnit,
                    interval_count AS intervalCount, trial_days AS trialDays,
                    status, revision, created_at AS createdAt, updated_at AS updatedAt
             FROM billing_prices
             WHERE plan_id = :plan AND (:include_inactive = 1 OR status = :active)
             ORDER BY currency, amount_minor, code, id',
            [
                'plan' => $planId,
                'include_inactive' => $includeInactive ? 1 : 0,
                'active' => 'active',
            ],
        );
    }

    public function price(string $priceId): ?array
    {
        return $this->one(
            'SELECT p.id, p.plan_id AS planId, p.code, p.currency,
                    p.amount_minor AS amountMinor, p.interval_unit AS intervalUnit,
                    p.interval_count AS intervalCount, p.trial_days AS trialDays,
                    p.status, p.revision, bp.status AS planStatus,
                    p.created_at AS createdAt, p.updated_at AS updatedAt
             FROM billing_prices p
             INNER JOIN billing_plans bp ON bp.id = p.plan_id
             WHERE p.id = :id',
            ['id' => $priceId],
        );
    }

    public function createPrice(
        string $id,
        string $planId,
        string $code,
        string $currency,
        int $amountMinor,
        string $intervalUnit,
        int $intervalCount,
        int $trialDays,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $this->connection->insert('billing_prices', [
            'id' => $id,
            'plan_id' => $planId,
            'code' => $code,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'interval_unit' => $intervalUnit,
            'interval_count' => $intervalCount,
            'trial_days' => $trialDays,
            'status' => 'active',
            'revision' => 1,
            'created_by_user_id' => $actorUserId,
            'updated_by_user_id' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function setPriceStatus(
        string $priceId,
        string $status,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE billing_prices
             SET status = :status, revision = revision + 1,
                 updated_by_user_id = :actor, updated_at = :updated
             WHERE id = :id AND revision = :revision',
            [
                'status' => $status,
                'actor' => $actorUserId,
                'updated' => $this->date($at),
                'id' => $priceId,
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function putProviderPriceReference(
        string $priceId,
        string $provider,
        string $providerReference,
        DateTimeImmutable $at,
    ): void {
        $existing = $this->providerPriceReference($priceId, $provider);
        if ($existing === null) {
            try {
                $this->connection->insert('billing_provider_price_refs', [
                    'price_id' => $priceId,
                    'provider' => $provider,
                    'provider_reference' => $providerReference,
                    'created_at' => $this->date($at),
                    'updated_at' => $this->date($at),
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                // A concurrent writer created the provider mapping first.
            }
        }
        $this->connection->update('billing_provider_price_refs', [
            'provider_reference' => $providerReference,
            'updated_at' => $this->date($at),
        ], ['price_id' => $priceId, 'provider' => $provider]);
    }

    public function providerPriceReference(string $priceId, string $provider): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT provider_reference FROM billing_provider_price_refs
             WHERE price_id = :price AND provider = :provider',
            ['price' => $priceId, 'provider' => $provider],
        );

        return $value === false ? null : (string) $value;
    }

    public function putEntitlement(
        string $id,
        string $planId,
        string $featureKey,
        string $valueJson,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void {
        $existing = $this->connection->fetchOne(
            'SELECT id FROM billing_entitlements
             WHERE plan_id = :plan AND feature_key = :feature',
            ['plan' => $planId, 'feature' => $featureKey],
        );
        if ($existing === false) {
            try {
                $this->connection->insert('billing_entitlements', [
                    'id' => $id,
                    'plan_id' => $planId,
                    'feature_key' => $featureKey,
                    'value_json' => $valueJson,
                    'updated_by_user_id' => $actorUserId,
                    'created_at' => $this->date($at),
                    'updated_at' => $this->date($at),
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                $existing = $this->connection->fetchOne(
                    'SELECT id FROM billing_entitlements
                     WHERE plan_id = :plan AND feature_key = :feature',
                    ['plan' => $planId, 'feature' => $featureKey],
                );
            }
        }
        if ($existing === false) {
            throw new \RuntimeException('Concurrent entitlement write could not be recovered.');
        }
        $this->connection->update('billing_entitlements', [
            'value_json' => $valueJson,
            'updated_by_user_id' => $actorUserId,
            'updated_at' => $this->date($at),
        ], ['id' => $existing]);
    }

    public function entitlements(string $planId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT feature_key AS featureKey, value_json AS valueJson
             FROM billing_entitlements WHERE plan_id = :plan ORDER BY feature_key',
            ['plan' => $planId],
        );
        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['featureKey']] = (string) $row['valueJson'];
        }

        return $values;
    }

    public function createPromotion(
        string $id,
        string $code,
        ?string $planId,
        string $discountType,
        ?int $percentOffBps,
        ?int $amountOffMinor,
        ?string $currency,
        ?int $maximumRedemptions,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $this->connection->insert('billing_promotion_codes', [
            'id' => $id,
            'code' => $code,
            'plan_id' => $planId,
            'discount_type' => $discountType,
            'percent_off_bps' => $percentOffBps,
            'amount_off_minor' => $amountOffMinor,
            'currency' => $currency,
            'maximum_redemptions' => $maximumRedemptions,
            'redemption_count' => 0,
            'valid_from' => $this->date($validFrom),
            'valid_until' => $validUntil === null ? null : $this->date($validUntil),
            'status' => 'active',
            'created_by_user_id' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function promotion(string $normalizedCode): ?array
    {
        return $this->one(
            'SELECT id, code, plan_id AS planId, discount_type AS discountType,
                    percent_off_bps AS percentOffBps, amount_off_minor AS amountOffMinor,
                    currency, maximum_redemptions AS maximumRedemptions,
                    redemption_count AS redemptionCount, valid_from AS validFrom,
                    valid_until AS validUntil, status
             FROM billing_promotion_codes WHERE code = :code',
            ['code' => $normalizedCode],
        );
    }

    public function createOverride(
        string $id,
        string $homeId,
        string $featureKey,
        string $valueJson,
        string $reason,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('billing_home_entitlement_overrides', [
            'id' => $id,
            'home_id' => $homeId,
            'feature_key' => $featureKey,
            'value_json' => $valueJson,
            'reason' => $reason,
            'valid_from' => $this->date($validFrom),
            'valid_until' => $validUntil === null ? null : $this->date($validUntil),
            'created_by_user_id' => $actorUserId,
            'revoked_by_user_id' => null,
            'revoked_at' => null,
            'created_at' => $this->date($at),
        ]);
    }

    public function revokeOverride(
        string $overrideId,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE billing_home_entitlement_overrides
             SET revoked_by_user_id = :actor, revoked_at = :revoked
             WHERE id = :id AND revoked_at IS NULL',
            [
                'actor' => $actorUserId,
                'revoked' => $this->date($at),
                'id' => $overrideId,
            ],
        ) === 1;
    }

    public function activeOverrides(string $homeId, DateTimeImmutable $at): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT feature_key AS featureKey, value_json AS valueJson
             FROM billing_home_entitlement_overrides
             WHERE home_id = :home AND revoked_at IS NULL
               AND valid_from <= :now
               AND (valid_until IS NULL OR valid_until > :now)
             ORDER BY valid_from, created_at, id',
            ['home' => $homeId, 'now' => $this->date($at)],
        );
        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['featureKey']] = (string) $row['valueJson'];
        }

        return $values;
    }

    public function subscription(string $homeId): ?array
    {
        return $this->one(
            'SELECT s.id, s.home_id AS homeId, s.plan_id AS planId,
                    bp.code AS planCode, s.price_id AS priceId, p.code AS priceCode,
                    s.provider, s.status, s.current_period_ends_at AS currentPeriodEndsAt,
                    s.cancel_at_period_end AS cancelAtPeriodEnd, s.revision,
                    s.created_at AS createdAt, s.updated_at AS updatedAt
             FROM billing_subscriptions s
             INNER JOIN billing_plans bp ON bp.id = s.plan_id
             INNER JOIN billing_prices p ON p.id = s.price_id
             WHERE s.home_id = :home',
            ['home' => $homeId],
        );
    }

    public function operatorSubscriptions(array $homeIds): array
    {
        if ($homeIds === []) {
            return [];
        }
        $subscriptions = $this->connection->executeQuery(
            'SELECT s.home_id AS homeId, s.status, bp.code AS planCode,
                    p.interval_unit AS billingCycle,
                    s.current_period_ends_at AS currentPeriodEnd
             FROM billing_subscriptions s
             INNER JOIN billing_plans bp ON bp.id = s.plan_id
             INNER JOIN billing_prices p ON p.id = s.price_id
             WHERE s.home_id IN (:homes)',
            ['homes' => array_values(array_unique($homeIds))],
            ['homes' => ArrayParameterType::STRING],
        )->fetchAllAssociative();
        $byHome = [];
        foreach ($subscriptions as $subscription) {
            $byHome[(string) $subscription['homeId']] = [
                'status' => (string) $subscription['status'],
                'planCode' => $subscription['planCode'] === null
                    ? null
                    : (string) $subscription['planCode'],
                'billingCycle' => $subscription['billingCycle'] === null
                    ? null
                    : (string) $subscription['billingCycle'],
                'currentPeriodEnd' => $subscription['currentPeriodEnd'] === null
                    ? null
                    : $this->atom((string) $subscription['currentPeriodEnd']),
            ];
        }

        return $byHome;
    }

    public function createCheckoutSession(
        string $id,
        string $homeId,
        string $priceId,
        string $provider,
        string $providerReference,
        string $redirectUrl,
        ?string $promotionId,
        string $actorUserId,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('billing_checkout_sessions', [
            'id' => $id,
            'home_id' => $homeId,
            'price_id' => $priceId,
            'promotion_id' => $promotionId,
            'provider' => $provider,
            'provider_reference' => $providerReference,
            'redirect_url' => $redirectUrl,
            'status' => 'pending',
            'created_by_user_id' => $actorUserId,
            'expires_at' => $this->date($expiresAt),
            'completed_at' => null,
            'created_at' => $this->date($at),
            'updated_at' => $this->date($at),
        ]);
    }

    public function checkoutByProviderReference(string $provider, string $providerReference): ?array
    {
        return $this->one(
            'SELECT c.id, c.home_id AS homeId, c.price_id AS priceId,
                    p.plan_id AS planId, c.promotion_id AS promotionId,
                    c.provider, c.provider_reference AS providerReference,
                    c.status, c.expires_at AS expiresAt
             FROM billing_checkout_sessions c
             INNER JOIN billing_prices p ON p.id = c.price_id
             WHERE c.provider = :provider AND c.provider_reference = :reference',
            ['provider' => $provider, 'reference' => $providerReference],
        );
    }

    public function claimWebhook(
        string $provider,
        string $eventId,
        string $eventType,
        string $payloadSha256,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $receivedAt,
    ): string {
        try {
            $this->connection->insert('billing_webhook_events', [
                'provider' => $provider,
                'provider_event_id' => $eventId,
                'event_type' => $eventType,
                'payload_sha256' => $payloadSha256,
                'status' => 'processing',
                'occurred_at' => $this->date($occurredAt),
                'received_at' => $this->date($receivedAt),
                'processed_at' => null,
            ]);

            return 'claimed';
        } catch (UniqueConstraintViolationException) {
            $existing = $this->connection->fetchAssociative(
                'SELECT payload_sha256 AS payloadSha256, status
                 FROM billing_webhook_events
                 WHERE provider = :provider AND provider_event_id = :event',
                ['provider' => $provider, 'event' => $eventId],
            );
            if ($existing === false || ! hash_equals((string) $existing['payloadSha256'], $payloadSha256)) {
                return 'conflict';
            }

            return (string) $existing['status'] === 'processing' ? 'in_progress' : 'duplicate';
        }
    }

    public function releaseWebhookClaim(
        string $provider,
        string $eventId,
        string $payloadSha256,
    ): void {
        $this->connection->delete('billing_webhook_events', [
            'provider' => $provider,
            'provider_event_id' => $eventId,
            'payload_sha256' => $payloadSha256,
            'status' => 'processing',
        ]);
    }

    public function applyWebhook(
        array $checkout,
        HostedCheckoutWebhook $event,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $completed = $event->eventType === 'checkout.completed';
        if ($completed) {
            $changed = $this->connection->executeStatement(
                'UPDATE billing_checkout_sessions
                 SET status = :status, completed_at = :completed, updated_at = :updated
                 WHERE id = :id AND status = :pending',
                [
                    'status' => 'completed',
                    'completed' => $now,
                    'updated' => $now,
                    'id' => $checkout['id'],
                    'pending' => 'pending',
                ],
            );
            if ($changed === 1 && $checkout['promotionId'] !== null) {
                $redeemed = $this->connection->executeStatement(
                    'UPDATE billing_promotion_codes
                     SET redemption_count = redemption_count + 1, updated_at = :updated
                     WHERE id = :id AND status = :status
                       AND (maximum_redemptions IS NULL OR redemption_count < maximum_redemptions)',
                    [
                        'updated' => $now,
                        'id' => $checkout['promotionId'],
                        'status' => 'active',
                    ],
                );
                if ($redeemed !== 1) {
                    throw new \DomainException('Promotion redemption capacity was exhausted.');
                }
            }
        }

        $status = $event->subscriptionStatus ?? match ($event->eventType) {
            'subscription.cancelled' => 'cancelled',
            'subscription.past_due' => 'past_due',
            default => 'active',
        };
        $existing = $this->connection->fetchOne(
            'SELECT id FROM billing_subscriptions WHERE home_id = :home',
            ['home' => $checkout['homeId']],
        );
        $values = [
            'plan_id' => $checkout['planId'],
            'price_id' => $checkout['priceId'],
            'provider' => $checkout['provider'],
            'provider_customer_reference' => $event->customerReference,
            'provider_subscription_reference' => $event->subscriptionReference,
            'status' => $status,
            'current_period_ends_at' => $event->currentPeriodEndsAt === null
                ? null
                : $this->date($event->currentPeriodEndsAt),
            'cancel_at_period_end' => $status === 'cancelled' ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($existing === false) {
            $this->connection->insert('billing_subscriptions', array_merge($values, [
                'id' => $checkout['id'],
                'home_id' => $checkout['homeId'],
                'revision' => 1,
                'created_at' => $now,
            ]));

            return;
        }
        $this->connection->update('billing_subscriptions', array_merge($values, [
            'revision' => (int) $this->connection->fetchOne(
                'SELECT revision FROM billing_subscriptions WHERE id = :id',
                ['id' => $existing],
            ) + 1,
        ]), ['id' => $existing]);
    }

    public function markWebhookProcessed(
        string $provider,
        string $eventId,
        DateTimeImmutable $at,
    ): void {
        $updated = $this->connection->update('billing_webhook_events', [
            'status' => 'processed',
            'processed_at' => $this->date($at),
        ], ['provider' => $provider, 'provider_event_id' => $eventId, 'status' => 'processing']);
        if ($updated !== 1) {
            throw new \RuntimeException('Webhook processing claim changed unexpectedly.');
        }
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $params);

        return $row === false ? null : $row;
    }

    private function date(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function atom(string $date): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
