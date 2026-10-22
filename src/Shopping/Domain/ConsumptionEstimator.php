<?php

declare(strict_types=1);

namespace Providentia\Shopping\Domain;

use DateTimeImmutable;

final class ConsumptionEstimator
{
    public const VERSION = 'reliable-count-interval-v2';

    /**
     * @param list<array<string, mixed>> $counts
     * @param list<array<string, mixed>> $inbound
     * @param list<DateTimeImmutable> $purchases
     * @return array<string, mixed>
     */
    public function estimate(
        array $counts,
        array $inbound,
        array $purchases,
        DateTimeImmutable $asOf,
    ): array {
        $counts = array_values(array_filter(
            $counts,
            static fn (array $count): bool => $count['at'] <= $asOf,
        ));
        $purchases = array_values(array_filter(
            $purchases,
            static fn (DateTimeImmutable $purchase): bool => $purchase <= $asOf,
        ));
        usort($counts, static fn (array $left, array $right): int => $left['at'] <=> $right['at']);
        usort($purchases, static fn (DateTimeImmutable $left, DateTimeImmutable $right): int => $left <=> $right);
        $totalConsumption = FixedDecimal::zero();
        $totalResidual = FixedDecimal::zero();
        $coverageDays = 0;
        $rates = [];
        $limitations = [];
        $intervalEvidence = [];
        $evidenceFrom = null;
        $evidenceTo = null;
        $quantitiesAt = [];
        $conflictingTimes = [];
        foreach ($counts as $count) {
            $key = $count['at']->format('U.u');
            $quantity = FixedDecimal::from((string) $count['quantity'])->toString();
            if (isset($quantitiesAt[$key]) && $quantitiesAt[$key] !== $quantity) {
                $conflictingTimes[$key] = true;
            }
            $quantitiesAt[$key] = $quantity;
        }

        for ($index = 1, $length = count($counts); $index < $length; $index++) {
            $start = $counts[$index - 1];
            $end = $counts[$index];
            $seconds = $end['at']->getTimestamp() - $start['at']->getTimestamp();
            if ($seconds <= 0) {
                continue;
            }
            $days = max(1, intdiv($seconds, 86400));
            $received = FixedDecimal::zero();
            $knownConsumption = FixedDecimal::zero();
            $otherOutflow = FixedDecimal::zero();
            $netMovement = FixedDecimal::zero();
            $reviewReason = ($start['unit'] ?? null) !== ($end['unit'] ?? null)
                || ($start['scope'] ?? null) !== ($end['scope'] ?? null)
                ? 'incompatible_count_scope_or_unit'
                : null;
            if (
                isset($conflictingTimes[$start['at']->format('U.u')])
                || isset($conflictingTimes[$end['at']->format('U.u')])
            ) {
                $reviewReason = 'conflicting_count_observations';
            }
            foreach ($inbound as $movement) {
                $quantity = FixedDecimal::from((string) $movement['quantity']);
                if ($quantity->compare(FixedDecimal::zero()) === 0) {
                    continue;
                }
                if (($movement['movementType'] ?? '') === 'count-reconciliation') {
                    continue;
                }
                if (
                    $movement['at'] > $asOf
                    && !(($movement['dateOnly'] ?? false) && ($movement['acceptedAtCutoff'] ?? false))
                ) {
                    continue;
                }
                $day = $movement['at']->format('Y-m-d');
                $startDay = $start['calendarDay'] ?? $start['at']->format('Y-m-d');
                $endDay = $end['calendarDay'] ?? $end['at']->format('Y-m-d');
                if (
                    ($movement['dateOnly'] ?? false) === true
                    && ($day === $startDay || $day === $endDay)
                ) {
                    $reviewReason = 'same_day_purchase_order_uncertain';
                    continue;
                }
                if (($movement['dateOnly'] ?? false) === true) {
                    if ($day <= $startDay || $day >= $endDay) {
                        continue;
                    }
                } elseif ($movement['at'] <= $start['at'] || $movement['at'] > $end['at']) {
                    continue;
                }
                if (
                    isset($start['unit'])
                    && ($movement['unit'] ?? null) !== $start['unit']
                ) {
                    $reviewReason = 'incompatible_movement_unit';
                    continue;
                }
                $netMovement = $netMovement->add($quantity);
                if ($quantity->isPositive()) {
                    $received = $received->add($quantity);
                } elseif (($movement['movementType'] ?? '') === 'consumption') {
                    $knownConsumption = $knownConsumption->add($quantity->absolute());
                } else {
                    $otherOutflow = $otherOutflow->add($quantity->absolute());
                }
            }
            $residual = FixedDecimal::from((string) $start['quantity'])
                ->add($netMovement)
                ->subtract(FixedDecimal::from((string) $end['quantity']));
            if ($residual->compare(FixedDecimal::zero()) < 0) {
                $reviewReason ??= 'negative_residual';
            }
            $estimatedUsage = $knownConsumption->add($residual);
            $intervalEvidence[] = [
                'from' => $start['at']->format(DATE_ATOM),
                'to' => $end['at']->format(DATE_ATOM),
                'unit' => $start['unit'] ?? null,
                'scope' => $start['scope'] ?? 'home',
                'openingQuantity' => (string) $start['quantity'],
                'incomingQuantity' => $received->toString(),
                'knownConsumption' => $knownConsumption->toString(),
                'otherRecordedOutflow' => $otherOutflow->toString(),
                'closingQuantity' => (string) $end['quantity'],
                'residualDepletion' => $residual->toString(),
                'estimatedUsage' => $reviewReason === null ? $estimatedUsage->toString() : null,
                'status' => $reviewReason === null ? 'accepted' : 'review_required',
                'reviewReason' => $reviewReason,
            ];
            if ($reviewReason !== null) {
                $limitations[] = 'A count interval requires review: ' . $reviewReason . '.';
                continue;
            }
            $totalConsumption = $totalConsumption->add($estimatedUsage);
            $totalResidual = $totalResidual->add($residual);
            $coverageDays += $days;
            $rates[] = $estimatedUsage->divideByInt($days);
            $evidenceFrom ??= $start['at'];
            $evidenceTo = $end['at'];
        }

        $intervals = count($rates);
        $dailyRate = $intervals === 0 || $coverageDays === 0
            ? FixedDecimal::zero()
            : $totalConsumption->divideByInt($coverageDays);
        $variability = FixedDecimal::zero();
        foreach ($rates as $rate) {
            $variability = $variability->add($rate->subtract($dailyRate)->absolute());
        }
        if ($intervals > 0) {
            $variability = $variability->divideByInt($intervals);
        }
        $latestCount = $evidenceTo ?? ($counts === [] ? null : $counts[array_key_last($counts)]['at']);
        $recencyDays = $latestCount === null
            ? PHP_INT_MAX
            : max(0, intdiv($asOf->getTimestamp() - $latestCount->getTimestamp(), 86400));
        [$score, $band] = $this->confidence($intervals, $coverageDays, $recencyDays);
        if ($intervals === 0) {
            $limitations[] = 'Two complete reliable counts are required before a consumption rate is estimated.';
        }
        if ($recencyDays > 45) {
            $limitations[] = 'The most recent reliable count is stale.';
        }
        [$purchaseCadenceDays, $nextExpectedShoppingAt] = $this->purchaseCadence(
            $purchases,
            $asOf,
        );
        if ($purchaseCadenceDays === null) {
            $limitations[] = 'At least two historical purchases are required to estimate purchase cadence.';
        }
        $limitations[] = 'Residual depletion is estimated usage or unexplained outflow, not exact food consumption.';
        $limitations[] = 'Seasonality is disabled until a longer evidence history is available.';

        return [
            'method' => self::VERSION,
            'dailyRate' => $dailyRate->toString(),
            'variability' => $variability->toString(),
            'sampleIntervals' => $intervals,
            'coverageDays' => $coverageDays,
            'purchaseSamples' => count($purchases),
            'purchaseCadenceDays' => $purchaseCadenceDays,
            'nextExpectedShoppingAt' => $nextExpectedShoppingAt,
            'confidenceScore' => $score,
            'confidenceBand' => $band,
            'evidenceFrom' => $evidenceFrom,
            'evidenceTo' => $evidenceTo,
            'totalEstimatedUsage' => $intervals === 0 ? null : $totalConsumption->toString(),
            'residualDepletion' => $intervals === 0 ? null : $totalResidual->toString(),
            'intervalEvidence' => $intervalEvidence,
            'limitations' => array_values(array_unique($limitations)),
        ];
    }

    /** @return array{string, string} */
    private function confidence(int $intervals, int $coverageDays, int $recencyDays): array
    {
        if ($intervals === 0) {
            return ['0.1000', 'low'];
        }
        if ($intervals === 1) {
            return [$coverageDays >= 21 && $recencyDays <= 45 ? '0.4500' : '0.3000', 'low'];
        }
        if ($intervals === 2) {
            return $coverageDays >= 45 && $recencyDays <= 30
                ? ['0.7000', 'medium']
                : ['0.6000', 'medium'];
        }

        return $coverageDays >= 90 && $recencyDays <= 30
            ? ['0.8500', 'high']
            : ['0.7500', 'medium'];
    }

    /**
     * @param list<DateTimeImmutable> $purchases
     * @return array{int|null, DateTimeImmutable|null}
     */
    private function purchaseCadence(array $purchases, DateTimeImmutable $asOf): array
    {
        if (count($purchases) < 2) {
            return [null, null];
        }
        $intervals = [];
        for ($index = 1, $length = count($purchases); $index < $length; $index++) {
            $seconds = $purchases[$index]->getTimestamp() - $purchases[$index - 1]->getTimestamp();
            if ($seconds > 0) {
                $intervals[] = max(1, intdiv($seconds, 86400));
            }
        }
        if ($intervals === []) {
            return [null, null];
        }
        sort($intervals, SORT_NUMERIC);
        $middle = intdiv(count($intervals), 2);
        $cadence = count($intervals) % 2 === 1
            ? $intervals[$middle]
            : max(1, intdiv($intervals[$middle - 1] + $intervals[$middle], 2));
        $latest = $purchases[array_key_last($purchases)];
        $elapsedDays = max(0, intdiv($asOf->getTimestamp() - $latest->getTimestamp(), 86400));
        $periods = intdiv(max(0, $elapsedDays - 1), $cadence) + 1;

        return [$cadence, $latest->modify('+' . ($periods * $cadence) . ' days')];
    }
}
