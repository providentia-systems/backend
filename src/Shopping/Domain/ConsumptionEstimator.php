<?php

declare(strict_types=1);

namespace Providentia\Shopping\Domain;

use DateTimeImmutable;

final class ConsumptionEstimator
{
    public const VERSION = 'reliable-count-interval-v1';

    /**
     * @param list<array{at: DateTimeImmutable, quantity: string}> $counts
     * @param list<array{at: DateTimeImmutable, quantity: string}> $inbound
     * @param list<DateTimeImmutable> $purchases
     * @return array<string, mixed>
     */
    public function estimate(
        array $counts,
        array $inbound,
        array $purchases,
        DateTimeImmutable $asOf,
    ): array {
        usort($counts, static fn (array $left, array $right): int => $left['at'] <=> $right['at']);
        usort($purchases, static fn (DateTimeImmutable $left, DateTimeImmutable $right): int => $left <=> $right);
        $totalConsumption = FixedDecimal::zero();
        $coverageDays = 0;
        $rates = [];
        $limitations = [];

        for ($index = 1, $length = count($counts); $index < $length; $index++) {
            $start = $counts[$index - 1];
            $end = $counts[$index];
            $seconds = $end['at']->getTimestamp() - $start['at']->getTimestamp();
            if ($seconds <= 0) {
                continue;
            }
            $days = max(1, intdiv($seconds, 86400));
            $received = FixedDecimal::zero();
            foreach ($inbound as $movement) {
                if ($movement['at'] > $start['at'] && $movement['at'] <= $end['at']) {
                    $received = $received->add(FixedDecimal::from($movement['quantity'])->maxZero());
                }
            }
            $raw = FixedDecimal::from($start['quantity'])
                ->add($received)
                ->subtract(FixedDecimal::from($end['quantity']));
            if ($raw->compare(FixedDecimal::zero()) < 0) {
                $limitations[] = 'A count interval implied negative consumption and was clamped to zero.';
            }
            $consumption = $raw->maxZero();
            $totalConsumption = $totalConsumption->add($consumption);
            $coverageDays += $days;
            $rates[] = $consumption->divideByInt($days);
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
        $latestCount = $counts === [] ? null : $counts[array_key_last($counts)]['at'];
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
            'evidenceFrom' => $counts === [] ? null : $counts[0]['at'],
            'evidenceTo' => $latestCount,
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
