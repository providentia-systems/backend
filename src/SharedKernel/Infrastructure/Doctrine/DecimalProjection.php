<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Doctrine;

use UnexpectedValueException;

/** Project database decimals without exposing driver-specific JSON number types. */
final class DecimalProjection
{
    public static function string(mixed $value): string
    {
        if (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) === 1) {
            // MySQL/MariaDB return exact decimal strings; never round-trip them
            // through a float. SQLite's numeric affinity can instead return a
            // native number, including scientific notation for small quantities.
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            $decimal = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
            return $decimal === '-0' ? '0' : $decimal;
        }

        throw new UnexpectedValueException('The stored decimal is invalid.');
    }
}
