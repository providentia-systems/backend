<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Purchasing;

use PHPUnit\Framework\TestCase;
use Providentia\Purchasing\Domain\PurchaseAmounts;

final class PurchaseAmountsTest extends TestCase
{
    public function testExplicitTotalsPreserveReceiptAndLineDiscountsIncludingZero(): void
    {
        $lines = [$this->line('3', '10', '25')];
        $declared = PurchaseAmounts::receipt('20.00', $lines);
        self::assertSame('20', $declared['totalAmount']);
        self::assertSame('20', $declared['knownSubtotal']);
        self::assertSame('declared', $declared['amountSource']);
        self::assertTrue($declared['pricingComplete']);
        self::assertSame('25', PurchaseAmounts::receipt(null, $lines)['totalAmount']);
        self::assertSame('0', PurchaseAmounts::receipt('0', $lines)['totalAmount']);
        self::assertSame('0', PurchaseAmounts::receipt(null, [$this->line('3', '10', '0')])['totalAmount']);
        self::assertSame('0', PurchaseAmounts::receipt('0', [])['totalAmount']);
    }

    public function testDerivationUsesExactPerLineHalfUpRoundingAndArbitraryPrecision(): void
    {
        $amounts = PurchaseAmounts::receipt(null, [
            $this->line('0.5', '0.01', null),
            $this->line('0.5', '0.01', null),
            $this->line('0.00000001', '0.01', null),
            $this->line('999999999.99999999', '9999999999999999.99', null),
        ]);
        self::assertSame('9999999999999999890000000.02', $amounts['totalAmount']);
        self::assertSame('derived', $amounts['amountSource']);
        self::assertSame(4, $amounts['derivedLineCount']);
        self::assertSame(4, $amounts['pricedLineCount']);
    }

    public function testPartialUnknownAndFreePurchasesHaveDifferentStates(): void
    {
        $partial = PurchaseAmounts::receipt(null, [
            $this->line('1', null, '0'),
            $this->line('2', null, null),
        ]);
        self::assertNull($partial['totalAmount']);
        self::assertSame('0', $partial['knownSubtotal']);
        self::assertFalse($partial['pricingComplete']);
        self::assertSame('partial', $partial['amountSource']);
        self::assertSame(1, $partial['pricedLineCount']);
        foreach ([[], [$this->line('1', null, null)]] as $lines) {
            $unknown = PurchaseAmounts::receipt(null, $lines);
            self::assertNull($unknown['totalAmount']);
            self::assertNull($unknown['knownSubtotal']);
            self::assertSame('unknown', $unknown['amountSource']);
            self::assertFalse($unknown['pricingComplete']);
        }
        $free = PurchaseAmounts::receipt(null, [$this->line('1', '0', null)]);
        self::assertSame('0', $free['totalAmount']);
        self::assertTrue($free['pricingComplete']);
    }

    public function testAggregateDisclosesUnknownReceiptsWithoutErasingKnownSubtotals(): void
    {
        $declared = PurchaseAmounts::receipt('10', []);
        $derived = PurchaseAmounts::receipt(null, [$this->line('3', '10', '25')]);
        $partial = PurchaseAmounts::receipt(null, [
            $this->line('1', null, '5'), $this->line('2', null, null),
        ]);
        $unknown = PurchaseAmounts::receipt(null, []);
        $complete = PurchaseAmounts::aggregate([$declared, $derived]);
        self::assertSame('35', $complete['totalAmount']);
        self::assertSame('mixed', $complete['amountSource']);
        self::assertSame(2, $complete['completeReceiptCount']);
        $all = PurchaseAmounts::aggregate([$declared, $derived, $partial, $unknown]);
        self::assertNull($all['totalAmount']);
        self::assertSame('40', $all['knownSubtotal']);
        self::assertSame('partial', $all['amountSource']);
        self::assertSame(4, $all['receiptCount']);
        self::assertSame(1, $all['declaredReceiptCount']);
        self::assertSame(1, $all['derivedReceiptCount']);
        self::assertSame(1, $all['partialReceiptCount']);
        self::assertSame(1, $all['unknownReceiptCount']);
        self::assertNull(PurchaseAmounts::aggregate([$unknown])['knownSubtotal']);
        self::assertSame('unknown', PurchaseAmounts::aggregate([$unknown])['amountSource']);
        self::assertSame('0', PurchaseAmounts::aggregate([])['totalAmount']);
    }

    /** @return array{quantity: string, unitPrice: ?string, lineTotal: ?string} */
    private function line(string $quantity, ?string $unitPrice, ?string $lineTotal): array
    {
        return ['quantity' => $quantity, 'unitPrice' => $unitPrice, 'lineTotal' => $lineTotal];
    }
}
