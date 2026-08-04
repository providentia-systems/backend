<?php

declare(strict_types=1);

namespace Providentia\Billing\Http;

use DateTimeImmutable;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Billing\Application\BillingService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class BillingHandler implements RequestHandlerInterface
{
    public function __construct(
        private BillingService $billing,
        private string $action,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->action === 'plans.available') {
            return new JsonResponse(['data' => $this->billing->availablePlans()]);
        }
        $identity = $this->identity($request);
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $this->rejectPaymentCredentials($body);

        return match ($this->action) {
            'operator.plans.list' => new JsonResponse(['data' => $this->billing->operatorPlans($identity)]),
            'operator.plans.create' => new JsonResponse($this->billing->createPlan(
                $identity,
                (string) ($body['code'] ?? ''),
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
            ), 201),
            'operator.plans.update' => new JsonResponse($this->billing->updatePlan(
                $identity,
                (string) $request->getAttribute('planId', ''),
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
                (string) ($body['status'] ?? ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'operator.prices.create' => new JsonResponse($this->billing->createPrice(
                $identity,
                (string) $request->getAttribute('planId', ''),
                (string) ($body['code'] ?? ''),
                (string) ($body['currency'] ?? ''),
                (int) ($body['amountMinor'] ?? -1),
                (string) ($body['intervalUnit'] ?? ''),
                (int) ($body['intervalCount'] ?? 0),
                (int) ($body['trialDays'] ?? 0),
            ), 201),
            'operator.prices.status' => new JsonResponse($this->billing->setPriceStatus(
                $identity,
                (string) $request->getAttribute('priceId', ''),
                (string) ($body['status'] ?? ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'operator.provider-prices.put' => $this->providerPrice($identity, $request, $body),
            'operator.entitlements.put' => $this->entitlement($identity, $request, $body),
            'operator.promotions.create' => new JsonResponse($this->billing->createPromotion(
                $identity,
                (string) ($body['code'] ?? ''),
                isset($body['planId']) ? (string) $body['planId'] : null,
                (string) ($body['discountType'] ?? ''),
                isset($body['percentOffBps']) ? (int) $body['percentOffBps'] : null,
                isset($body['amountOffMinor']) ? (int) $body['amountOffMinor'] : null,
                isset($body['currency']) ? (string) $body['currency'] : null,
                isset($body['maximumRedemptions']) ? (int) $body['maximumRedemptions'] : null,
                $this->date($body['validFrom'] ?? null, 'validFrom'),
                $this->optionalDate($body['validUntil'] ?? null, 'validUntil'),
            ), 201),
            'operator.overrides.put' => new JsonResponse($this->billing->putHomeOverride(
                $identity,
                (string) $request->getAttribute('homeId', ''),
                (string) ($body['featureKey'] ?? ''),
                $this->entitlementValue($body),
                (string) ($body['reason'] ?? ''),
                $this->date($body['validFrom'] ?? null, 'validFrom'),
                $this->optionalDate($body['validUntil'] ?? null, 'validUntil'),
            ), 201),
            'operator.overrides.revoke' => $this->revokeOverride($identity, $request),
            'home.summary' => new JsonResponse($this->billing->homeSummary(
                $identity,
                (string) $request->getAttribute('homeId', ''),
            )),
            'checkout.create' => new JsonResponse($this->billing->startCheckout(
                $identity,
                (string) $request->getAttribute('homeId', ''),
                (string) ($body['priceId'] ?? ''),
                (string) ($body['provider'] ?? ''),
                (string) ($body['successUrl'] ?? ''),
                (string) ($body['cancelUrl'] ?? ''),
                isset($body['promotionCode']) ? (string) $body['promotionCode'] : null,
            ), 201),
            default => throw new \LogicException('Unknown billing action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function providerPrice(
        AuthenticatedIdentity $identity,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->billing->setProviderPriceReference(
            $identity,
            (string) $request->getAttribute('priceId', ''),
            (string) $request->getAttribute('provider', ''),
            (string) ($body['providerReference'] ?? ''),
        );

        return new EmptyResponse(204);
    }

    /** @param array<string, mixed> $body */
    private function entitlement(
        AuthenticatedIdentity $identity,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->billing->putEntitlement(
            $identity,
            (string) $request->getAttribute('planId', ''),
            (string) $request->getAttribute('featureKey', ''),
            $this->entitlementValue($body),
        );

        return new EmptyResponse(204);
    }

    private function revokeOverride(
        AuthenticatedIdentity $identity,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $this->billing->revokeHomeOverride(
            $identity,
            (string) $request->getAttribute('overrideId', ''),
        );

        return new EmptyResponse(204);
    }

    private function identity(ServerRequestInterface $request): AuthenticatedIdentity
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }

        return $identity;
    }

    /** @param array<string, mixed> $body */
    private function entitlementValue(array $body): bool|int|string|null
    {
        $value = $body['value'] ?? null;
        if ($value !== null && ! is_bool($value) && ! is_int($value) && ! is_string($value)) {
            throw new HttpProblem(422, 'Invalid entitlement', 'Entitlement value must be a scalar or null.');
        }

        return $value;
    }

    private function date(mixed $value, string $field): DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new HttpProblem(422, 'Invalid billing date', $field . ' is required.');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new HttpProblem(422, 'Invalid billing date', $field . ' is invalid.');
        }
    }

    private function optionalDate(mixed $value, string $field): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->date($value, $field);
    }

    /** @param array<string, mixed> $body */
    private function rejectPaymentCredentials(array $body): void
    {
        $stack = [$body];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (! is_array($current)) {
                continue;
            }
            foreach ($current as $key => $value) {
                $normalized = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $key));
                if (in_array($normalized, [
                    'cardnumber',
                    'pan',
                    'cvc',
                    'cvv',
                    'trackdata',
                    'magneticstripe',
                ], true)) {
                    throw new HttpProblem(
                        422,
                        'Payment credentials rejected',
                        'Card data must be entered only on the provider-hosted checkout.',
                    );
                }
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
    }
}
