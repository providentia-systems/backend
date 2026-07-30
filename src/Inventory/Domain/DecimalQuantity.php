<?php

declare(strict_types=1);

namespace Providentia\Inventory\Domain;

use InvalidArgumentException;

final readonly class DecimalQuantity
{
    private const SCALE = 8;
    private const FACTOR = 100000000;

    private function __construct(private int $scaled)
    {
    }

    public static function quantity(string|int $value): self
    {
        $quantity = self::parse($value);
        if ($quantity->scaled < 0) {
            throw new InvalidArgumentException('A quantity cannot be negative.');
        }

        return $quantity;
    }

    public static function delta(string|int $value): self
    {
        return self::parse($value);
    }

    public function subtract(self $other): self
    {
        return new self($this->scaled - $other->scaled);
    }

    public function add(self $other): self
    {
        return new self($this->scaled + $other->scaled);
    }

    public function isZero(): bool
    {
        return $this->scaled === 0;
    }

    public function toString(): string
    {
        $negative = $this->scaled < 0;
        $absolute = abs($this->scaled);
        $whole = intdiv($absolute, self::FACTOR);
        $fraction = str_pad((string) ($absolute % self::FACTOR), self::SCALE, '0', STR_PAD_LEFT);
        $value = (string) $whole;
        if ((int) $fraction !== 0) {
            $value .= '.' . rtrim($fraction, '0');
        }

        return ($negative ? '-' : '') . $value;
    }

    private static function parse(string|int $value): self
    {
        $raw = trim((string) $value);
        if (preg_match('/^-?(?:0|[1-9]\d{0,8})(?:\.\d{1,8})?$/', $raw) !== 1) {
            throw new InvalidArgumentException('Quantity must be a decimal with at most eight fractional digits.');
        }
        $negative = str_starts_with($raw, '-');
        $unsigned = ltrim($raw, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $scaled = ((int) $whole * self::FACTOR)
            + (int) str_pad($fraction, self::SCALE, '0', STR_PAD_RIGHT);

        return new self($negative ? -$scaled : $scaled);
    }
}
