<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Shopping;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Shopping\Domain\ConsumptionEstimator;

final class ConsumptionEstimatorBoundaryTest extends TestCase
{
    public function testCountsAreSortedAndInboundBoundaryBelongsToOnlyTheEndingInterval(): void
    {
        $jan1 = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $jan2 = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $jan3 = new DateTimeImmutable('2026-01-03T00:00:00+00:00');

        $estimate = (new ConsumptionEstimator())->estimate(
            [
                ['at' => $jan3, 'quantity' => '8'],
                ['at' => $jan1, 'quantity' => '10'],
                ['at' => $jan2, 'quantity' => '9'],
            ],
            [
                ['at' => $jan1, 'quantity' => '5'],
                ['at' => $jan2, 'quantity' => '2'],
                ['at' => $jan3, 'quantity' => '3'],
            ],
            [],
            $jan3,
        );

        self::assertSame(ConsumptionEstimator::VERSION, $estimate['method']);
        self::assertSame('3.5', $estimate['dailyRate']);
        self::assertSame('0.5', $estimate['variability']);
        self::assertSame(2, $estimate['sampleIntervals']);
        self::assertSame(2, $estimate['coverageDays']);
        self::assertSame($jan1, $estimate['evidenceFrom']);
        self::assertSame($jan3, $estimate['evidenceTo']);
    }

    public function testZeroLengthIntervalsAreSkippedAndSubDayIntervalsCountAsOneDay(): void
    {
        $start = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $later = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $estimate = (new ConsumptionEstimator())->estimate(
            [
                ['at' => $start, 'quantity' => '10'],
                ['at' => $start, 'quantity' => '1'],
                ['at' => $later, 'quantity' => '0'],
            ],
            [],
            [],
            $later,
        );

        self::assertSame(1, $estimate['sampleIntervals']);
        self::assertSame(1, $estimate['coverageDays']);
        self::assertSame('1', $estimate['dailyRate']);
    }

    public function testOnlyNegativeConsumptionAddsTheClampLimitationAndDuplicatesAreRemoved(): void
    {
        $counts = [
            ['at' => new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 'quantity' => '5'],
            ['at' => new DateTimeImmutable('2026-01-02T00:00:00+00:00'), 'quantity' => '10'],
            ['at' => new DateTimeImmutable('2026-01-03T00:00:00+00:00'), 'quantity' => '15'],
        ];
        $negative = (new ConsumptionEstimator())->estimate(
            $counts,
            [],
            [],
            new DateTimeImmutable('2026-01-03T00:00:00+00:00'),
        );
        $clamp = 'A count interval implied negative consumption and was clamped to zero.';

        self::assertSame('0', $negative['dailyRate']);
        self::assertSame(1, count(array_keys($negative['limitations'], $clamp, true)));

        $zero = (new ConsumptionEstimator())->estimate(
            [
                ['at' => $counts[0]['at'], 'quantity' => '5'],
                ['at' => $counts[1]['at'], 'quantity' => '5'],
            ],
            [],
            [],
            $counts[1]['at'],
        );
        self::assertNotContains($clamp, $zero['limitations']);
    }

    /** @param list<int> $intervalDays */
    #[DataProvider('confidenceBoundaries')]
    public function testConfidenceBoundariesAreExact(
        array $intervalDays,
        int $recencyDays,
        string $score,
        string $band,
        bool $stale,
    ): void {
        $counts = $this->counts($intervalDays);
        $latest = $counts[array_key_last($counts)]['at'];
        $estimate = (new ConsumptionEstimator())->estimate(
            $counts,
            [],
            [],
            $latest->modify('+' . $recencyDays . ' days'),
        );

        self::assertSame($score, $estimate['confidenceScore']);
        self::assertSame($band, $estimate['confidenceBand']);
        self::assertSame(array_sum($intervalDays), $estimate['coverageDays']);
        self::assertSame(
            $stale,
            in_array('The most recent reliable count is stale.', $estimate['limitations'], true),
        );
    }

    /** @return iterable<string, array{list<int>, int, string, string, bool}> */
    public static function confidenceBoundaries(): iterable
    {
        yield 'one interval exact strong boundary' => [[21], 45, '0.4500', 'low', false];
        yield 'one interval short coverage' => [[20], 45, '0.3000', 'low', false];
        yield 'one interval stale by one day' => [[21], 46, '0.3000', 'low', true];
        yield 'two intervals exact medium boundary' => [[22, 23], 30, '0.7000', 'medium', false];
        yield 'two intervals short coverage' => [[22, 22], 30, '0.6000', 'medium', false];
        yield 'two intervals old evidence' => [[22, 23], 31, '0.6000', 'medium', false];
        yield 'three intervals exact high boundary' => [[30, 30, 30], 30, '0.8500', 'high', false];
        yield 'three intervals short coverage' => [[30, 30, 29], 30, '0.7500', 'medium', false];
        yield 'three intervals old evidence' => [[30, 30, 30], 31, '0.7500', 'medium', false];
    }

    public function testPurchaseHistoryIsSortedAndEvenAndOddMediansAreDeterministic(): void
    {
        $estimator = new ConsumptionEstimator();
        $even = $estimator->estimate(
            [],
            [],
            [
                new DateTimeImmutable('2026-01-31T00:00:00+00:00'),
                new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                new DateTimeImmutable('2026-01-11T00:00:00+00:00'),
            ],
            new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
        );
        self::assertSame(3, $even['purchaseSamples']);
        self::assertSame(15, $even['purchaseCadenceDays']);
        self::assertSame('2026-02-15', $this->date($even['nextExpectedShoppingAt']));

        $odd = $estimator->estimate(
            [],
            [],
            [
                new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                new DateTimeImmutable('2026-01-03T00:00:00+00:00'),
                new DateTimeImmutable('2026-01-13T00:00:00+00:00'),
                new DateTimeImmutable('2026-02-12T00:00:00+00:00'),
            ],
            new DateTimeImmutable('2026-02-12T00:00:00+00:00'),
        );
        self::assertSame(10, $odd['purchaseCadenceDays']);
        self::assertSame('2026-02-22', $this->date($odd['nextExpectedShoppingAt']));
    }

    public function testTwoPurchasesAreEnoughAndDuplicateTimestampsProvideNoCadence(): void
    {
        $estimator = new ConsumptionEstimator();
        $two = $estimator->estimate(
            [],
            [],
            [
                new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
            ],
            new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
        );
        self::assertSame(1, $two['purchaseCadenceDays']);
        self::assertSame('2026-01-03', $this->date($two['nextExpectedShoppingAt']));
        self::assertNotContains(
            'At least two historical purchases are required to estimate purchase cadence.',
            $two['limitations'],
        );

        $same = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $duplicates = $estimator->estimate([], [], [$same, $same], $same);
        self::assertNull($duplicates['purchaseCadenceDays']);
        self::assertNull($duplicates['nextExpectedShoppingAt']);
    }

    #[DataProvider('nextPurchaseBoundaries')]
    public function testNextPurchaseAlwaysFallsOnTheNextCadenceBoundary(
        string $asOf,
        string $expected,
    ): void {
        $estimate = (new ConsumptionEstimator())->estimate(
            [],
            [],
            [
                new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                new DateTimeImmutable('2026-01-11T00:00:00+00:00'),
            ],
            new DateTimeImmutable($asOf . 'T00:00:00+00:00'),
        );

        self::assertSame(10, $estimate['purchaseCadenceDays']);
        self::assertSame($expected, $this->date($estimate['nextExpectedShoppingAt']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nextPurchaseBoundaries(): iterable
    {
        yield 'on latest purchase' => ['2026-01-11', '2026-01-21'];
        yield 'one day before cadence' => ['2026-01-20', '2026-01-21'];
        yield 'on cadence date' => ['2026-01-21', '2026-01-21'];
        yield 'one day after cadence' => ['2026-01-22', '2026-01-31'];
    }

    /** @param list<int> $intervalDays @return list<array{at: DateTimeImmutable, quantity: string}> */
    private function counts(array $intervalDays): array
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $counts = [['at' => $at, 'quantity' => '100']];
        foreach ($intervalDays as $index => $days) {
            $at = $at->modify('+' . $days . ' days');
            $counts[] = ['at' => $at, 'quantity' => (string) (99 - $index)];
        }

        return $counts;
    }

    private function date(mixed $value): string
    {
        self::assertInstanceOf(DateTimeImmutable::class, $value);

        return $value->format('Y-m-d');
    }
}
