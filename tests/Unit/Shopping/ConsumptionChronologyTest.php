<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Shopping;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Shopping\Domain\ConsumptionEstimator;

final class ConsumptionChronologyTest extends TestCase
{
    public function testLatePurchaseExplainsResidualWithoutInventingADailySchedule(): void
    {
        $result = $this->estimate([
            ['at' => new DateTimeImmutable('2026-10-10T00:00:00Z'), 'quantity' => '4', 'dateOnly' => true],
        ]);

        self::assertSame(1, $result['sampleIntervals']);
        self::assertSame('3', $result['totalEstimatedUsage']);
        self::assertSame('3', $result['residualDepletion']);
        self::assertSame('4', $result['intervalEvidence'][0]['incomingQuantity']);
        self::assertSame('accepted', $result['intervalEvidence'][0]['status']);
        self::assertStringContainsString('unexplained outflow', implode(' ', $result['limitations']));
    }

    public function testKnownConsumptionAndWasteAreNotCountedAgainAsResidual(): void
    {
        $result = $this->estimate([
            ['at' => new DateTimeImmutable('2026-10-10T00:00:00Z'), 'quantity' => '8'],
            [
                'at' => new DateTimeImmutable('2026-10-11T00:00:00Z'),
                'quantity' => '-3', 'movementType' => 'consumption',
            ],
            ['at' => new DateTimeImmutable('2026-10-12T00:00:00Z'), 'quantity' => '-1', 'movementType' => 'waste'],
            ['at' => new DateTimeImmutable('2026-10-13T00:00:00Z'), 'quantity' => '-1', 'movementType' => 'return'],
        ]);

        self::assertSame('2', $result['residualDepletion']);
        self::assertSame('5', $result['totalEstimatedUsage']);
        self::assertSame('3', $result['intervalEvidence'][0]['knownConsumption']);
        self::assertSame('2', $result['intervalEvidence'][0]['otherRecordedOutflow']);
    }

    public function testAmbiguousPurchaseDoesNotHideOtherRecordedIntervalEvidence(): void
    {
        $result = $this->estimate([
            ['at' => new DateTimeImmutable('2026-10-20T00:00:00Z'), 'quantity' => '4', 'dateOnly' => true],
            ['at' => new DateTimeImmutable('2026-10-10T00:00:00Z'), 'quantity' => '2'],
            ['at' => new DateTimeImmutable('2026-10-11T00:00:00Z'), 'quantity' => '-1', 'movementType' => 'waste'],
        ]);

        self::assertSame(0, $result['sampleIntervals']);
        self::assertNull($result['totalEstimatedUsage']);
        self::assertSame('same_day_purchase_order_uncertain', $result['intervalEvidence'][0]['reviewReason']);
        self::assertSame('2', $result['intervalEvidence'][0]['incomingQuantity']);
        self::assertSame('1', $result['intervalEvidence'][0]['otherRecordedOutflow']);
        self::assertSame('0', $result['intervalEvidence'][0]['residualDepletion']);
    }

    public function testConsecutiveDailyPurchasesRetainOneDayCadence(): void
    {
        $result = (new ConsumptionEstimator())->estimate([], [], [
            new DateTimeImmutable('2026-10-20T00:00:00Z'),
            new DateTimeImmutable('2026-10-21T00:00:00Z'),
            new DateTimeImmutable('2026-10-22T00:00:00Z'),
        ], new DateTimeImmutable('2026-10-22T12:00:00Z'));

        self::assertSame(1, $result['purchaseCadenceDays']);
        self::assertSame('2026-10-23T00:00:00+00:00', $result['nextExpectedShoppingAt']->format(DATE_ATOM));
        self::assertSame(0, $result['sampleIntervals']);
        self::assertNull($result['totalEstimatedUsage']);
    }

    public function testDateOnlyPurchasesOnEitherBoundaryNeedReview(): void
    {
        foreach (['2026-10-01', '2026-10-20'] as $day) {
            $result = $this->estimate([
                ['at' => new DateTimeImmutable($day . 'T00:00:00Z'), 'quantity' => '4', 'dateOnly' => true],
            ]);
            self::assertSame(0, $result['sampleIntervals']);
            self::assertNull($result['totalEstimatedUsage']);
            self::assertNull($result['residualDepletion']);
            self::assertSame('same_day_purchase_order_uncertain', $result['intervalEvidence'][0]['reviewReason']);
            self::assertNull($result['intervalEvidence'][0]['estimatedUsage']);
        }
    }

    public function testIncompatibleUnitsAndFutureEvidenceNeverBecomeUsageSamples(): void
    {
        $result = (new ConsumptionEstimator())->estimate(
            [
                ['at' => new DateTimeImmutable('2026-10-01T12:00:00Z'), 'quantity' => '2', 'unit' => 'kg'],
                ['at' => new DateTimeImmutable('2026-10-20T12:00:00Z'), 'quantity' => '3', 'unit' => 'units'],
                ['at' => new DateTimeImmutable('2026-11-01T12:00:00Z'), 'quantity' => '0', 'unit' => 'units'],
            ],
            [],
            [new DateTimeImmutable('2026-11-10T00:00:00Z')],
            new DateTimeImmutable('2026-10-22T12:00:00Z'),
        );

        self::assertSame(0, $result['sampleIntervals']);
        self::assertSame(0, $result['purchaseSamples']);
        self::assertCount(1, $result['intervalEvidence']);
        self::assertSame('incompatible_count_scope_or_unit', $result['intervalEvidence'][0]['reviewReason']);
    }

    public function testHouseholdCalendarDayControlsSameDayOrderingAcrossUtcMidnight(): void
    {
        $result = (new ConsumptionEstimator())->estimate(
            [
                [
                    'at' => new DateTimeImmutable('2026-10-01T12:00:00Z'),
                    'quantity' => '2', 'calendarDay' => '2026-10-01',
                ],
                [
                    'at' => new DateTimeImmutable('2026-10-20T23:00:00Z'),
                    'quantity' => '3', 'calendarDay' => '2026-10-21',
                ],
            ],
            [['at' => new DateTimeImmutable('2026-10-21T00:00:00Z'), 'quantity' => '4', 'dateOnly' => true]],
            [],
            new DateTimeImmutable('2026-10-22T12:00:00Z'),
        );

        self::assertSame(0, $result['sampleIntervals']);
        self::assertSame('same_day_purchase_order_uncertain', $result['intervalEvidence'][0]['reviewReason']);
    }

    public function testConflictingCountsAtOneInstantAreNotArbitrarilySelectedForUsage(): void
    {
        $at = new DateTimeImmutable('2026-10-01T12:00:00Z');
        $result = (new ConsumptionEstimator())->estimate(
            [
                ['at' => $at, 'quantity' => '2'],
                ['at' => $at, 'quantity' => '5'],
                ['at' => $at->modify('+10 days'), 'quantity' => '1'],
            ],
            [],
            [],
            $at->modify('+20 days'),
        );

        self::assertSame(0, $result['sampleIntervals']);
        self::assertSame('conflicting_count_observations', $result['intervalEvidence'][0]['reviewReason']);
    }

    /**
     * @param list<array<string, mixed>> $movements
     * @return array<string, mixed>
     */
    private function estimate(array $movements): array
    {
        return (new ConsumptionEstimator())->estimate(
            [
                ['at' => new DateTimeImmutable('2026-10-01T12:00:00Z'), 'quantity' => '2'],
                ['at' => new DateTimeImmutable('2026-10-20T12:00:00Z'), 'quantity' => '3'],
            ],
            $movements,
            [],
            new DateTimeImmutable('2026-10-22T12:00:00Z'),
        );
    }
}
