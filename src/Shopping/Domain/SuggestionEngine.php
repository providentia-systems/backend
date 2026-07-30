<?php

declare(strict_types=1);

namespace Providentia\Shopping\Domain;

use DateTimeImmutable;

final class SuggestionEngine
{
    public const VERSION = 'deterministic-replenishment-v1';

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $estimate
     * @return array<string, mixed>
     */
    public function suggest(
        array $product,
        array $estimate,
        int $defaultHorizonDays,
        DateTimeImmutable $asOf,
    ): array {
        $neverSuggest = (bool) ($product['neverSuggest'] ?? false);
        $snoozeUntil = $product['snoozeUntil'] ?? null;
        if (
            $neverSuggest
            || (is_string($snoozeUntil) && $snoozeUntil >= $asOf->format('Y-m-d'))
        ) {
            return [
                'eligible' => false,
                'reason' => $neverSuggest ? 'never-suggest preference' : 'snoozed preference',
            ];
        }
        $configuredCoverage = (int) ($product['targetCoverageDays'] ?? 0);
        $leadTime = max(0, (int) ($product['leadTimeDays'] ?? 0));
        $cadenceHorizon = $this->cadenceHorizon($estimate['nextExpectedShoppingAt'] ?? null, $asOf);
        $horizonDays = min(365, max(
            1,
            $configuredCoverage > 0
                ? $configuredCoverage
                : ($cadenceHorizon ?? $defaultHorizonDays),
        ));
        $demandDays = min(365, $horizonDays + $leadTime);
        $factualStock = FixedDecimal::from((string) ($product['factualStock'] ?? '0'));
        $usableStock = $factualStock->maxZero();
        $minimum = $product['minimumQuantity'] === null
            ? FixedDecimal::zero()
            : FixedDecimal::from((string) $product['minimumQuantity'])->maxZero();
        if (! $minimum->isPositive() && (bool) ($product['alwaysKeep'] ?? false)) {
            $minimum = FixedDecimal::from('1');
        }
        $dailyRate = FixedDecimal::from((string) $estimate['dailyRate'])->maxZero();
        $expectedDemand = (int) $estimate['sampleIntervals'] === 0
            ? FixedDecimal::zero()
            : $dailyRate->multiplyByInt($demandDays);
        $required = $expectedDemand->add($minimum)->subtract($usableStock)->maxZero();
        /** @var list<string> $limitations */
        $limitations = is_array($estimate['limitations']) ? $estimate['limitations'] : [];
        if ((int) $estimate['sampleIntervals'] === 0) {
            $limitations[] = 'Insufficient history: the calculation uses only the configured minimum reserve.';
        }

        return [
            'eligible' => $required->isPositive(),
            'expectedDemand' => $expectedDemand->toString(),
            'safetyStock' => $minimum->toString(),
            'factualStock' => $factualStock->toString(),
            'usableStock' => $usableStock->toString(),
            'requiredQuantity' => $required->toString(),
            'horizonDays' => $demandDays,
            'confidenceScore' => $estimate['confidenceScore'],
            'confidenceBand' => $estimate['confidenceBand'],
            'selectedPackId' => $product['preferredPackId'] ?? $product['packId'] ?? null,
            'packCount' => $required->isPositive()
                ? $required->ceilRatio(FixedDecimal::from('1'))
                : 0,
            'factors' => [
                [
                    'key' => 'expected-demand',
                    'value' => $expectedDemand->toString(),
                    'days' => $demandDays,
                ],
                [
                    'key' => 'purchase-cadence',
                    'days' => $estimate['purchaseCadenceDays'] ?? null,
                    'nextExpectedShoppingAt' => $estimate['nextExpectedShoppingAt'] instanceof DateTimeImmutable
                        ? $estimate['nextExpectedShoppingAt']->format(DATE_ATOM)
                        : null,
                ],
                ['key' => 'minimum-reserve', 'value' => $minimum->toString()],
                ['key' => 'factual-stock', 'value' => $factualStock->toString()],
                ['key' => 'required-quantity', 'value' => $required->toString()],
            ],
            'limitations' => array_values(array_unique($limitations)),
        ];
    }

    private function cadenceHorizon(mixed $nextExpectedShoppingAt, DateTimeImmutable $asOf): ?int
    {
        if (! $nextExpectedShoppingAt instanceof DateTimeImmutable) {
            return null;
        }
        $seconds = $nextExpectedShoppingAt->getTimestamp() - $asOf->getTimestamp();

        return max(1, intdiv(max(0, $seconds) + 86399, 86400));
    }
}
