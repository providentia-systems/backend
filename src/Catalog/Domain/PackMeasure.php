<?php

declare(strict_types=1);

namespace Providentia\Catalog\Domain;

use DomainException;

/** Decimal(20,8) multiplication without binary floating-point conversions. */
final class PackMeasure
{
    public static function normalize(string $amount, string $factor, int $multiplicity): string
    {
        $value = self::multiply(self::scaled($amount), self::scaled($factor));
        $value = self::multiply($value, (string) $multiplicity);
        $value = str_pad($value, 17, '0', STR_PAD_LEFT);
        $scaled = substr($value, 0, -8);
        $whole = ltrim(substr($scaled, 0, -8), '0');
        if (strlen($whole) > 12) {
            throw new DomainException('The normalized pack measure exceeds the supported range.');
        }
        return ($whole === '' ? '0' : $whole) . '.' . substr($scaled, -8);
    }

    private static function scaled(string $value): string
    {
        if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/', $value) !== 1) {
            throw new DomainException(
                'Measures require a non-negative decimal with up to eight decimal places.',
            );
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        return $whole . str_pad($fraction, 8, '0');
    }

    private static function multiply(string $left, string $right): string
    {
        $digits = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; --$i) {
            for ($j = strlen($right) - 1; $j >= 0; --$j) {
                $position = $i + $j + 1;
                $sum = $digits[$position] + (int) $left[$i] * (int) $right[$j];
                $digits[$position] = $sum % 10;
                $digits[$position - 1] += intdiv($sum, 10);
            }
        }
        return ltrim(implode('', $digits), '0') ?: '0';
    }
}
