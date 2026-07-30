<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Shopping;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Shopping\Domain\ConsumptionEstimator;

final class ConsumptionEstimatorTest extends TestCase
{
    public function testReliableCountIntervalsProduceAnExplainableRate(): void
    {
        $estimate = (new ConsumptionEstimator())->estimate(
            [
                ['at' => new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 'quantity' => '10'],
                ['at' => new DateTimeImmutable('2026-01-11T00:00:00+00:00'), 'quantity' => '4'],
                ['at' => new DateTimeImmutable('2026-01-21T00:00:00+00:00'), 'quantity' => '5'],
            ],
            [
                ['at' => new DateTimeImmutable('2026-01-05T00:00:00+00:00'), 'quantity' => '2'],
                ['at' => new DateTimeImmutable('2026-01-15T00:00:00+00:00'), 'quantity' => '3'],
            ],
            [
                new DateTimeImmutable('2025-12-28T00:00:00+00:00'),
                new DateTimeImmutable('2026-01-08T00:00:00+00:00'),
                new DateTimeImmutable('2026-01-19T00:00:00+00:00'),
            ],
            new DateTimeImmutable('2026-01-22T00:00:00+00:00'),
        );

        self::assertSame('0.5', $estimate['dailyRate']);
        self::assertSame('0.3', $estimate['variability']);
        self::assertSame(2, $estimate['sampleIntervals']);
        self::assertSame(20, $estimate['coverageDays']);
        self::assertSame(11, $estimate['purchaseCadenceDays']);
        self::assertInstanceOf(DateTimeImmutable::class, $estimate['nextExpectedShoppingAt']);
        /** @var DateTimeImmutable $nextExpectedShoppingAt */
        $nextExpectedShoppingAt = $estimate['nextExpectedShoppingAt'];
        self::assertSame(
            '2026-01-30',
            $nextExpectedShoppingAt->format('Y-m-d'),
        );
        self::assertSame('medium', $estimate['confidenceBand']);
    }

    public function testInsufficientCountsNeverFabricateAConsumptionRate(): void
    {
        $estimate = (new ConsumptionEstimator())->estimate(
            [['at' => new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 'quantity' => '10']],
            [],
            [],
            new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
        );

        self::assertSame('0', $estimate['dailyRate']);
        self::assertSame(0, $estimate['sampleIntervals']);
        self::assertSame('low', $estimate['confidenceBand']);
    }
}
