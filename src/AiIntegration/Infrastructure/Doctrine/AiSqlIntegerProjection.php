<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Doctrine;

use UnexpectedValueException;

/** Normalizes documented integer columns, never identifiers or arbitrary JSON. */
final class AiSqlIntegerProjection
{
    /** @var array<string, int> */
    private const MINIMUMS = [
        'revision' => 1,
        'keyVersion' => 1,
        'estimatedCostMicros' => 0,
        'maxAttempts' => 1,
        'maxTotalTokens' => 1,
        'maxEstimatedCostMicros' => 0,
    ];

    /**
     * Database drivers may return integer columns as decimal strings. Reject
     * malformed, negative and overflowing values rather than silently casting
     * them to zero. A missing column is legitimate for a narrower SELECT;
     * a present NULL is legitimate only for a profile without a credential.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalize(array $row): array
    {
        foreach (self::MINIMUMS as $field => $minimum) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            if ($field === 'keyVersion' && $value === null) {
                continue;
            }
            if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
                $maximum = (string) PHP_INT_MAX;
                if (
                    strlen($value) > strlen($maximum)
                    || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
                ) {
                    throw new UnexpectedValueException('The persisted AI metadata contains an invalid integer.');
                }
                $value = (int) $value;
            }
            if (! is_int($value) || $value < $minimum) {
                throw new UnexpectedValueException('The persisted AI metadata contains an invalid integer.');
            }
            $row[$field] = $value;
        }

        return $row;
    }
}
