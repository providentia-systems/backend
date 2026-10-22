<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use Providentia\Inventory\Domain\StockChronology;

final class StockChronologyTest extends TestCase
{
    public function testTimedMovementAfterMigratedSnapshotAppliesOnSameDayAndDateOnlyRemainsUncertain(): void
    {
        $baseline = ['quantity' => '3', 'calendarDay' => '2026-10-22', 'recorded_at' => '2026-10-22 08:00:00'];
        $result = (new StockChronology())->project([], [
            ['id' => 'adjust', 'occurredAt' => '2026-10-22 09:00:00', 'quantityDelta' => '2',
                'unit' => 'kg', 'dateOnly' => false],
            ['id' => 'purchase', 'occurredAt' => '2026-10-22 00:00:00', 'quantityDelta' => '4',
                'unit' => 'kg', 'dateOnly' => true],
        ], 'kg', $baseline);
        self::assertSame('5', $result['quantity']);
        self::assertSame('4', $result['pendingQuantity']);
        self::assertTrue($result['uncertain']);
    }

    public function testExactDecimalsDoNotOverflowWhenManyPurchasesExceedInputQuantityLimit(): void
    {
        $result = (new StockChronology())->project([], [
            ['id' => 'a', 'occurredAt' => '2026-10-22', 'quantityDelta' => '999999999.12345678', 'unit' => 'kg'],
            ['id' => 'b', 'occurredAt' => '2026-10-22', 'quantityDelta' => '999999999.00000001', 'unit' => 'kg'],
        ], 'kg');
        self::assertSame('1999999998.12345679', $result['quantity']);
    }

    public function testOldIncompatibleUnitsBeforeTrustedCountDoNotInvalidateNewMeasuredStock(): void
    {
        $result = (new StockChronology())->project([
            ['id' => 'count', 'occurredAt' => '2026-10-20 12:00:00', 'quantity' => '3', 'unit' => 'kg'],
        ], [
            ['id' => 'old', 'occurredAt' => '2026-10-10', 'quantityDelta' => '4000', 'unit' => 'g', 'dateOnly' => true],
        ], 'kg');
        self::assertSame('3', $result['quantity']);
        self::assertFalse($result['uncertain']);
    }

    public function testConflictingSimultaneousObservationsRequireExplicitReview(): void
    {
        $result = (new StockChronology())->project([
            ['id' => 'a', 'occurredAt' => '2026-10-20 12:00:00', 'quantity' => '3', 'unit' => 'kg'],
            ['id' => 'b', 'occurredAt' => '2026-10-20 12:00:00', 'quantity' => '4', 'unit' => 'kg'],
        ], [], 'kg');
        self::assertTrue($result['uncertain']);
        self::assertStringContainsString('Conflicting', implode(' ', $result['limitations']));
    }
}
