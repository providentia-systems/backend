<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

use DateTimeImmutable;

interface BillingStore
{
    public function homeExists(string $homeId): bool;

    /**
     * @return list<array<string, mixed>> */
    public function plans(bool $includeInactive): array;

    /**
     * @return array<string, mixed>|null */
    public function plan(string $planId): ?array;

    public function createPlan(
        string $id,
        string $code,
        string $name,
        string $description,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void;

    public function updatePlan(
        string $planId,
        string $name,
        string $description,
        string $status,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    /**
     * @return list<array<string, mixed>> */
    public function prices(string $planId, bool $includeInactive): array;

    /**
     * @return array<string, mixed>|null */
    public function price(string $priceId): ?array;

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
    ): void;

    public function setPriceStatus(
        string $priceId,
        string $status,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    public function putProviderPriceReference(
        string $priceId,
        string $provider,
        string $providerReference,
        DateTimeImmutable $at,
    ): void;

    public function providerPriceReference(string $priceId, string $provider): ?string;

    public function putEntitlement(
        string $id,
        string $planId,
        string $featureKey,
        string $valueJson,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void;

    /**
     * @return array<string, string> */
    public function entitlements(string $planId): array;

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
    ): void;

    /**
     * @return array<string, mixed>|null */
    public function promotion(string $normalizedCode): ?array;

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
    ): void;

    public function revokeOverride(
        string $overrideId,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool;

    /**
     * @return array<string, string> */
    public function activeOverrides(string $homeId, DateTimeImmutable $at): array;

    /**
     * @return array<string, mixed>|null */
    public function subscription(string $homeId): ?array;

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
    ): void;

    /**
     * @return array<string, mixed>|null */
    public function checkoutByProviderReference(string $provider, string $providerReference): ?array;

    /**
     * Returns claimed, in_progress, duplicate, or conflict. A conflict means
     * the provider reused an event identifier for different signed bytes.
     */
    public function claimWebhook(
        string $provider,
        string $eventId,
        string $eventType,
        string $payloadSha256,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $receivedAt,
    ): string;

    public function releaseWebhookClaim(
        string $provider,
        string $eventId,
        string $payloadSha256,
    ): void;

    /**
     * @param array<string, mixed> $checkout */
    public function applyWebhook(
        array $checkout,
        HostedCheckoutWebhook $event,
        DateTimeImmutable $at,
    ): void;

    public function markWebhookProcessed(
        string $provider,
        string $eventId,
        DateTimeImmutable $at,
    ): void;
}
