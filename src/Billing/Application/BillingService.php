<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

use DateTimeImmutable;
use Providentia\Billing\Domain\SubscriptionStatus;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Throwable;

final readonly class BillingService
{
    public function __construct(
        private BillingStore $billing,
        private BillingAuthorization $authorization,
        private CheckoutGatewayRegistry $gateways,
        private BillingConfiguration $configuration,
        private UuidGenerator $ids,
        private Clock $clock,
        private TransactionManager $transactions,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function availablePlans(): array
    {
        $this->configuration->requireEnabled();

        return $this->planCatalog(false);
    }

    /** @return list<array<string, mixed>> */
    public function operatorPlans(AuthenticatedIdentity $identity): array
    {
        $this->authorization->requireOperator($identity);

        return $this->planCatalog(true);
    }

    /** @return array{id: string, code: string, revision: int, status: string} */
    public function createPlan(
        AuthenticatedIdentity $identity,
        string $code,
        string $name,
        string $description,
    ): array {
        $this->authorization->requireOperator($identity);
        $code = $this->code($code, 'plan');
        $name = $this->bounded($name, 2, 120, 'plan name');
        $description = $this->bounded($description, 0, 1000, 'plan description');
        $id = $this->ids->generate();
        $this->billing->createPlan(
            $id,
            $code,
            $name,
            $description,
            $identity->userId,
            $this->clock->now(),
        );

        return ['id' => $id, 'code' => $code, 'revision' => 1, 'status' => 'draft'];
    }

    /** @return array{id: string, revision: int, status: string} */
    public function updatePlan(
        AuthenticatedIdentity $identity,
        string $planId,
        string $name,
        string $description,
        string $status,
        int $expectedRevision,
    ): array {
        $this->authorization->requireOperator($identity);
        $name = $this->bounded($name, 2, 120, 'plan name');
        $description = $this->bounded($description, 0, 1000, 'plan description');
        if (! in_array($status, ['draft', 'active', 'archived'], true) || $expectedRevision < 1) {
            throw new Problem(422, 'Invalid plan', 'Plan status or revision is invalid.');
        }
        $updated = $this->billing->updatePlan(
            $planId,
            $name,
            $description,
            $status,
            $expectedRevision,
            $identity->userId,
            $this->clock->now(),
        );
        if (! $updated) {
            throw new Problem(409, 'Plan changed', 'The plan revision is no longer current.');
        }

        return ['id' => $planId, 'revision' => $expectedRevision + 1, 'status' => $status];
    }

    /** @return array{id: string, code: string, revision: int, status: string} */
    public function createPrice(
        AuthenticatedIdentity $identity,
        string $planId,
        string $code,
        string $currency,
        int $amountMinor,
        string $intervalUnit,
        int $intervalCount,
        int $trialDays,
    ): array {
        $this->authorization->requireOperator($identity);
        if ($this->billing->plan($planId) === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $code = $this->code($code, 'price');
        $currency = mb_strtoupper(trim($currency));
        if (
            preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || $amountMinor < 0
            || $amountMinor > 999999999999
            || ! in_array($intervalUnit, ['one_time', 'month', 'year'], true)
            || ($intervalUnit === 'one_time' && ($intervalCount !== 1 || $trialDays !== 0))
            || ($intervalUnit !== 'one_time' && ($intervalCount < 1 || $intervalCount > 36))
            || $trialDays < 0
            || $trialDays > 365
        ) {
            throw new Problem(422, 'Invalid price', 'Price, currency, interval, or trial is invalid.');
        }
        $id = $this->ids->generate();
        $this->billing->createPrice(
            $id,
            $planId,
            $code,
            $currency,
            $amountMinor,
            $intervalUnit,
            $intervalCount,
            $trialDays,
            $identity->userId,
            $this->clock->now(),
        );

        return ['id' => $id, 'code' => $code, 'revision' => 1, 'status' => 'active'];
    }

    /** @return array{id: string, revision: int, status: string} */
    public function setPriceStatus(
        AuthenticatedIdentity $identity,
        string $priceId,
        string $status,
        int $expectedRevision,
    ): array {
        $this->authorization->requireOperator($identity);
        if (! in_array($status, ['active', 'retired'], true) || $expectedRevision < 1) {
            throw new Problem(422, 'Invalid price', 'Price status or revision is invalid.');
        }
        if (! $this->billing->setPriceStatus(
            $priceId,
            $status,
            $expectedRevision,
            $identity->userId,
            $this->clock->now(),
        )) {
            throw new Problem(409, 'Price changed', 'The price revision is no longer current.');
        }

        return ['id' => $priceId, 'revision' => $expectedRevision + 1, 'status' => $status];
    }

    public function setProviderPriceReference(
        AuthenticatedIdentity $identity,
        string $priceId,
        string $provider,
        string $providerReference,
    ): void {
        $this->authorization->requireOperator($identity);
        $provider = $this->provider($provider);
        $providerReference = $this->bounded($providerReference, 1, 191, 'provider price reference');
        if ($this->billing->price($priceId) === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $this->billing->putProviderPriceReference(
            $priceId,
            $provider,
            $providerReference,
            $this->clock->now(),
        );
    }

    public function putEntitlement(
        AuthenticatedIdentity $identity,
        string $planId,
        string $featureKey,
        bool|int|string|null $value,
    ): void {
        $this->authorization->requireOperator($identity);
        $featureKey = $this->featureKey($featureKey);
        if ($this->billing->plan($planId) === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $this->billing->putEntitlement(
            $this->ids->generate(),
            $planId,
            $featureKey,
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $identity->userId,
            $this->clock->now(),
        );
    }

    /** @return array{id: string, code: string} */
    public function createPromotion(
        AuthenticatedIdentity $identity,
        string $code,
        ?string $planId,
        string $discountType,
        ?int $percentOffBps,
        ?int $amountOffMinor,
        ?string $currency,
        ?int $maximumRedemptions,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
    ): array {
        $this->authorization->requireOperator($identity);
        $code = mb_strtoupper($this->code($code, 'promotion'));
        if ($planId !== null && $this->billing->plan($planId) === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $currency = $currency === null ? null : mb_strtoupper(trim($currency));
        $percentValid = $discountType === 'percent'
            && $percentOffBps !== null
            && $percentOffBps >= 1
            && $percentOffBps <= 10000
            && $amountOffMinor === null
            && $currency === null;
        $fixedValid = $discountType === 'fixed'
            && $amountOffMinor !== null
            && $amountOffMinor >= 1
            && $amountOffMinor <= 999999999999
            && $percentOffBps === null
            && $currency !== null
            && preg_match('/^[A-Z]{3}$/', $currency) === 1;
        if (
            (! $percentValid && ! $fixedValid)
            || ($maximumRedemptions !== null && $maximumRedemptions < 1)
            || ($validUntil !== null && $validUntil <= $validFrom)
        ) {
            throw new Problem(422, 'Invalid promotion', 'Promotion discount or validity is invalid.');
        }
        $id = $this->ids->generate();
        $this->billing->createPromotion(
            $id,
            $code,
            $planId,
            $discountType,
            $percentOffBps,
            $amountOffMinor,
            $currency,
            $maximumRedemptions,
            $validFrom,
            $validUntil,
            $identity->userId,
            $this->clock->now(),
        );

        return ['id' => $id, 'code' => $code];
    }

    /** @return array{id: string, featureKey: string} */
    public function putHomeOverride(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $featureKey,
        bool|int|string|null $value,
        string $reason,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
    ): array {
        $this->authorization->requireOperator($identity);
        if (! $this->billing->homeExists($homeId)) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $featureKey = $this->featureKey($featureKey);
        $reason = $this->bounded($reason, 3, 500, 'override reason');
        if ($validUntil !== null && $validUntil <= $validFrom) {
            throw new Problem(422, 'Invalid override', 'Override validity is invalid.');
        }
        $id = $this->ids->generate();
        $this->billing->createOverride(
            $id,
            $homeId,
            $featureKey,
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $reason,
            $validFrom,
            $validUntil,
            $identity->userId,
            $this->clock->now(),
        );

        return ['id' => $id, 'featureKey' => $featureKey];
    }

    public function revokeHomeOverride(
        AuthenticatedIdentity $identity,
        string $overrideId,
    ): void {
        $this->authorization->requireOperator($identity);
        if (! $this->billing->revokeOverride($overrideId, $identity->userId, $this->clock->now())) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
    }

    /** @return array<string, mixed> */
    public function homeSummary(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->configuration->requireEnabled();
        $this->authorization->requireHomeRead($identity, $homeId);
        $now = $this->clock->now();
        $subscription = $this->billing->subscription($homeId);
        $values = [];
        if ($subscription !== null) {
            $values = $this->decodeEntitlements(
                $this->billing->entitlements((string) $subscription['planId']),
            );
        }
        $values = array_replace(
            $values,
            $this->decodeEntitlements($this->billing->activeOverrides($homeId, $now)),
        );

        return ['subscription' => $subscription, 'entitlements' => $values];
    }

    /** @return array{id: string, provider: string, redirectUrl: string, expiresAt: string} */
    public function startCheckout(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $priceId,
        string $provider,
        string $successUrl,
        string $cancelUrl,
        ?string $promotionCode,
    ): array {
        $this->configuration->requireEnabled();
        $this->authorization->requireHomeManage($identity, $homeId);
        $provider = $this->provider($provider);
        $gateway = $this->gateways->require($provider);
        $price = $this->billing->price($priceId);
        if (
            $price === null
            || (string) $price['status'] !== 'active'
            || (string) $price['planStatus'] !== 'active'
        ) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $this->httpsUrl($successUrl, 'success URL');
        $this->httpsUrl($cancelUrl, 'cancel URL');
        $promotion = $this->checkoutPromotion(
            $promotionCode,
            (string) $price['planId'],
            (string) $price['currency'],
        );
        $id = $this->ids->generate();
        try {
            $session = $gateway->createSession(new HostedCheckoutRequest(
                $id,
                $homeId,
                $priceId,
                (string) $price['code'],
                $this->billing->providerPriceReference($priceId, $provider),
                (string) $price['currency'],
                (int) $price['amountMinor'],
                (string) $price['intervalUnit'],
                (int) $price['intervalCount'],
                $successUrl,
                $cancelUrl,
                $promotion === null ? null : (string) $promotion['code'],
                $this->decodeEntitlements($this->billing->entitlements((string) $price['planId'])),
            ));
        } catch (BillingProviderException $error) {
            $correctable = in_array($error->safeCode, [
                'paypal_plan_reference_missing',
                'paypal_promotion_plan_required',
            ], true);
            throw new Problem(
                $correctable ? 422 : 502,
                $correctable ? 'Checkout configuration incomplete' : 'Checkout provider failed',
                $correctable
                    ? $error->getMessage()
                    : 'The hosted checkout provider could not create a session.',
            );
        }
        $providerReference = $this->bounded(
            $session->providerReference,
            1,
            191,
            'provider checkout reference',
        );
        $this->httpsUrl($session->redirectUrl, 'hosted checkout URL');
        if ($session->expiresAt <= $this->clock->now()) {
            throw new Problem(502, 'Checkout provider failed', 'The provider returned an expired session.');
        }
        $this->transactions->transactional(function () use (
            $id,
            $homeId,
            $priceId,
            $provider,
            $providerReference,
            $session,
            $promotion,
            $identity,
        ): void {
            $this->billing->createCheckoutSession(
                $id,
                $homeId,
                $priceId,
                $provider,
                $providerReference,
                $session->redirectUrl,
                $promotion === null ? null : (string) $promotion['id'],
                $identity->userId,
                $session->expiresAt,
                $this->clock->now(),
            );
        });

        return [
            'id' => $id,
            'provider' => $provider,
            'redirectUrl' => $session->redirectUrl,
            'expiresAt' => $session->expiresAt->format(DATE_ATOM),
        ];
    }

    /** @param array<string, list<string>> $headers */
    public function acceptWebhook(string $provider, string $rawBody, array $headers): string
    {
        $this->configuration->requireEnabled();
        $provider = $this->provider($provider);
        if ($rawBody === '' || strlen($rawBody) > 1048576) {
            throw new Problem(413, 'Webhook rejected', 'Webhook payload size is outside policy.');
        }
        $gateway = $this->gateways->require($provider);
        try {
            $event = $gateway->verifyWebhook($rawBody, $headers);
        } catch (BillingProviderException) {
            throw new Problem(400, 'Webhook rejected', 'Webhook authentication or content is invalid.');
        }
        $this->validateWebhook($event);
        $now = $this->clock->now();
        $payloadSha256 = hash('sha256', $rawBody);

        $claim = $this->transactions->transactional(function () use (
            $provider,
            $event,
            $payloadSha256,
            $now,
        ): string {
            $claim = $this->billing->claimWebhook(
                $provider,
                $event->eventId,
                $event->eventType,
                $payloadSha256,
                $event->occurredAt,
                $now,
            );

            return $claim;
        });
        if ($claim === 'duplicate') {
            return 'duplicate';
        }
        if ($claim === 'in_progress') {
            throw new Problem(409, 'Webhook in progress', 'The provider should retry this event.');
        }
        if ($claim !== 'claimed') {
            throw new Problem(
                409,
                'Webhook identifier conflict',
                'The provider event identifier was reused for different content.',
            );
        }

        try {
            if ($event->eventType === 'checkout.approved') {
                if (! $gateway instanceof PayPalHostedCheckoutGateway) {
                    throw new BillingProviderException(
                        'provider_capture_unsupported',
                        'The provider cannot capture an approved checkout.',
                    );
                }
                $event = $gateway->captureApprovedOrder($event);
                $this->validateWebhook($event);
            }

            return $this->transactions->transactional(function () use ($provider, $event, $now): string {
                $checkout = $this->billing->checkoutByProviderReference(
                    $provider,
                    $event->checkoutReference,
                );
                if ($checkout === null) {
                    throw new Problem(422, 'Webhook rejected', 'The checkout reference is unknown.');
                }
                $this->billing->applyWebhook($checkout, $event, $now);
                $this->billing->markWebhookProcessed($provider, $event->eventId, $now);

                return 'processed';
            });
        } catch (Throwable $error) {
            $this->transactions->transactional(function () use (
                $provider,
                $event,
                $payloadSha256,
            ): void {
                $this->billing->releaseWebhookClaim($provider, $event->eventId, $payloadSha256);
            });
            if ($error instanceof Problem) {
                throw $error;
            }
            throw new Problem(502, 'Webhook processing failed', 'The provider event should be retried.');
        }
    }

    /** @return array<string, mixed>|null */
    private function checkoutPromotion(?string $code, string $planId, string $currency): ?array
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        $promotion = $this->billing->promotion(mb_strtoupper(trim($code)));
        $now = $this->clock->now();
        if (
            $promotion === null
            || (string) $promotion['status'] !== 'active'
            || ($promotion['planId'] !== null && (string) $promotion['planId'] !== $planId)
            || ($promotion['currency'] !== null && (string) $promotion['currency'] !== $currency)
            || new DateTimeImmutable((string) $promotion['validFrom']) > $now
            || (
                $promotion['validUntil'] !== null
                && new DateTimeImmutable((string) $promotion['validUntil']) <= $now
            )
            || (
                $promotion['maximumRedemptions'] !== null
                && (int) $promotion['redemptionCount'] >= (int) $promotion['maximumRedemptions']
            )
        ) {
            throw new Problem(422, 'Promotion unavailable', 'The promotion code is invalid or expired.');
        }

        return $promotion;
    }

    /** @return list<array<string, mixed>> */
    private function planCatalog(bool $includeInactive): array
    {
        $plans = $this->billing->plans($includeInactive);
        foreach ($plans as &$plan) {
            $planId = (string) $plan['id'];
            $plan['prices'] = $this->billing->prices($planId, $includeInactive);
            $plan['entitlements'] = $this->decodeEntitlements(
                $this->billing->entitlements($planId),
            );
        }
        unset($plan);

        return $plans;
    }

    private function validateWebhook(HostedCheckoutWebhook $event): void
    {
        if (
            preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $event->eventId) !== 1
            || preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $event->checkoutReference) !== 1
            || ! in_array($event->eventType, [
                'checkout.approved',
                'checkout.completed',
                'subscription.updated',
                'subscription.cancelled',
                'subscription.past_due',
            ], true)
            || (
                $event->subscriptionStatus !== null
                && ! in_array($event->subscriptionStatus, SubscriptionStatus::values(), true)
            )
        ) {
            throw new Problem(422, 'Webhook rejected', 'The verified webhook event is invalid.');
        }
    }

    private function provider(string $provider): string
    {
        $provider = mb_strtolower(trim($provider));
        if (
            ! in_array($provider, ['paypal', 'hosted_card'], true)
            || ! in_array($provider, $this->configuration->enabledProviders, true)
        ) {
            throw new Problem(
                422,
                'Checkout provider unavailable',
                'The selected hosted-checkout provider is not enabled.',
            );
        }

        return $provider;
    }

    private function code(string $value, string $label): string
    {
        $value = mb_strtolower(trim($value));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $value) !== 1) {
            throw new Problem(422, 'Invalid ' . $label, ucfirst($label) . ' code is invalid.');
        }

        return $value;
    }

    private function featureKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $value) !== 1) {
            throw new Problem(422, 'Invalid entitlement', 'Entitlement feature key is invalid.');
        }

        return $value;
    }

    private function bounded(string $value, int $minimum, int $maximum, string $label): string
    {
        $value = trim($value);
        if (mb_strlen($value) < $minimum || mb_strlen($value) > $maximum) {
            throw new Problem(422, 'Invalid billing input', ucfirst($label) . ' is outside policy.');
        }

        return $value;
    }

    private function httpsUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        if (
            $parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || strlen($url) > 2048
        ) {
            throw new Problem(422, 'Invalid checkout URL', ucfirst($label) . ' must be an HTTPS origin URL.');
        }
    }

    /**
     * @param array<string, string> $encoded
     * @return array<string, bool|int|string|null>
     */
    private function decodeEntitlements(array $encoded): array
    {
        $values = [];
        foreach ($encoded as $feature => $value) {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
            if ($decoded === null || is_bool($decoded) || is_int($decoded) || is_string($decoded)) {
                $values[$feature] = $decoded;
            }
        }

        return $values;
    }
}
