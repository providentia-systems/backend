<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Receipt spend is authoritative only when declared or completely priced. */
final class PurchaseAmounts
{
    /**
     * @param list<array{quantity: string, unitPrice: ?string, lineTotal: ?string}> $lines
     * @return array<string, mixed>
     */
    public static function receipt(?string $declaredTotal, array $lines): array
    {
        $subtotal = BigDecimal::zero();
        $priced = 0;
        $derived = 0;
        foreach ($lines as $line) {
            $amount = $line['lineTotal'];
            if ($amount === null && $line['unitPrice'] !== null) {
                // Round each derived purchase line, matching price observations.
                $amount = (string) BigDecimal::of($line['quantity'])
                    ->multipliedBy($line['unitPrice'])->toScale(2, RoundingMode::HALF_UP);
                $derived++;
            }
            if ($amount !== null) {
                $subtotal = $subtotal->plus($amount);
                $priced++;
            }
        }
        $complete = $declaredTotal !== null || ($lines !== [] && $priced === count($lines));
        $known = $declaredTotal !== null
            ? self::decimal(BigDecimal::of($declaredTotal))
            : ($priced > 0 ? self::decimal($subtotal) : null);

        return [
            'totalAmount' => $complete ? $known : null,
            'knownSubtotal' => $known,
            'pricingComplete' => $complete,
            'amountSource' => $declaredTotal !== null ? 'declared'
                : ($complete ? 'derived' : ($priced > 0 ? 'partial' : 'unknown')),
            'lineCount' => count($lines),
            'pricedLineCount' => $priced,
            'derivedLineCount' => $derived,
        ];
    }

    /**
     * Aggregate only one currency; callers own currency grouping.
     *
     * @param list<array<string, mixed>> $receipts
     * @return array<string, mixed>
     */
    public static function aggregate(array $receipts): array
    {
        $total = BigDecimal::zero();
        $hasKnownAmount = false;
        $counts = [
            'receiptCount' => count($receipts),
            'lineCount' => 0,
            'completeReceiptCount' => 0,
            'partialReceiptCount' => 0,
            'unknownReceiptCount' => 0,
            'declaredReceiptCount' => 0,
            'derivedReceiptCount' => 0,
        ];
        foreach ($receipts as $receipt) {
            $counts['lineCount'] += (int) $receipt['lineCount'];
            if ($receipt['pricingComplete']) {
                $counts['completeReceiptCount']++;
            }
            $source = (string) $receipt['amountSource'];
            $counts[$source . 'ReceiptCount']++;
            if ($receipt['knownSubtotal'] !== null) {
                $hasKnownAmount = true;
                $total = $total->plus((string) $receipt['knownSubtotal']);
            }
        }
        $complete = $counts['completeReceiptCount'] === count($receipts);
        $source = $complete
            ? ($counts['declaredReceiptCount'] === count($receipts) ? 'declared'
                : ($counts['derivedReceiptCount'] === count($receipts) ? 'derived' : 'mixed'))
            : ($hasKnownAmount ? 'partial' : 'unknown');
        $known = $hasKnownAmount || $receipts === [] ? self::decimal($total) : null;

        return $counts + [
            'totalAmount' => $complete ? $known : null,
            'knownSubtotal' => $known,
            'pricingComplete' => $complete,
            'amountSource' => $receipts === [] ? 'derived' : $source,
        ];
    }

    private static function decimal(BigDecimal $amount): string
    {
        return (string) $amount->strippedOfTrailingZeros();
    }
}
