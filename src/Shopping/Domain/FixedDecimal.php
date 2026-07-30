<?php

declare(strict_types=1);

namespace Providentia\Shopping\Domain;

use InvalidArgumentException;

final readonly class FixedDecimal
{
    private const SCALE = 8;
    private const FACTOR = 100000000;

    private function __construct(private int $scaled)
    {
    }

    public static function from(string|int $value): self
    {
        $raw = trim((string) $value);
        if (preg_match('/^-?(?:0|[1-9]\d{0,8})(?:\.\d{1,8})?$/', $raw) !== 1) {
            throw new InvalidArgumentException('Decimal value is outside the supported fixed-point range.');
        }
        $negative = str_starts_with($raw, '-');
        $unsigned = ltrim($raw, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $scaled = ((int) $whole * self::FACTOR)
            + (int) str_pad($fraction, self::SCALE, '0', STR_PAD_RIGHT);

        return new self($negative ? -$scaled : $scaled);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->scaled + $other->scaled);
    }

    public function subtract(self $other): self
    {
        return new self($this->scaled - $other->scaled);
    }

    public function multiplyByInt(int $multiplier): self
    {
        return new self($this->scaled * $multiplier);
    }

    public function divideByInt(int $divisor): self
    {
        if ($divisor <= 0) {
            throw new InvalidArgumentException('Fixed-point divisor must be positive.');
        }

        return new self(intdiv($this->scaled, $divisor));
    }

    public function divide(self $divisor): self
    {
        if ($divisor->scaled <= 0 || $this->scaled < 0) {
            throw new InvalidArgumentException('Fixed-point division requires non-negative values.');
        }
        $whole = intdiv($this->scaled, $divisor->scaled);
        $remainder = $this->scaled % $divisor->scaled;
        $fraction = 0;
        for ($position = 0; $position < self::SCALE; $position++) {
            $remainder *= 10;
            $fraction = ($fraction * 10) + intdiv($remainder, $divisor->scaled);
            $remainder %= $divisor->scaled;
        }

        return new self(($whole * self::FACTOR) + $fraction);
    }

    public function ceilRatio(self $divisor): int
    {
        if ($divisor->scaled <= 0 || $this->scaled < 0) {
            throw new InvalidArgumentException('Fixed-point ratio requires a positive divisor.');
        }

        return intdiv($this->scaled, $divisor->scaled)
            + ($this->scaled % $divisor->scaled === 0 ? 0 : 1);
    }

    public function absolute(): self
    {
        return new self(abs($this->scaled));
    }

    public function maxZero(): self
    {
        return $this->scaled < 0 ? self::zero() : $this;
    }

    public function isPositive(): bool
    {
        return $this->scaled > 0;
    }

    public function compare(self $other): int
    {
        return $this->scaled <=> $other->scaled;
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
}
