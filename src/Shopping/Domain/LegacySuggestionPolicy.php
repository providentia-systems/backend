<?php

declare(strict_types=1);

namespace Providentia\Shopping\Domain;

use InvalidArgumentException;

final class LegacySuggestionPolicy
{
    private const FACTOR = 100000000;

    /**
     * This policy exists only to preserve the approved PWA comparison baseline.
     * Phase 8 replaces it with movement-derived replenishment intelligence.
     */
    public function suggest(string $threeMonthPurchases, string $currentQuantity): int
    {
        $purchases = $this->scaled($threeMonthPurchases);
        $current = $this->scaled($currentQuantity);
        $need = $purchases - ($current * 3);
        if ($need <= 0) {
            return 0;
        }

        return max(1, intdiv($need + (3 * self::FACTOR) - 1, 3 * self::FACTOR));
    }

    private function scaled(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(?:0|[1-9]\d{0,8})(?:\.\d{1,8})?$/', $value) !== 1) {
            throw new InvalidArgumentException('Suggestion inputs must be non-negative decimals.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * self::FACTOR)
            + (int) str_pad($fraction, 8, '0', STR_PAD_RIGHT);
    }
}
