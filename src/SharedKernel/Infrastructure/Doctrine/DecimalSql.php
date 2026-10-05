<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Doctrine;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Pdo\Sqlite;
use RuntimeException;
use WeakMap;

/** Exact arithmetic for SQLite's text decimals; native DECIMAL SQL on MySQL/MariaDB. */
final class DecimalSql
{
    /** @var null|WeakMap<Sqlite, true> */
    private static ?WeakMap $registered = null;

    // Pass only developer-owned SQL fragments. Construct expressions for each
    // query so a reconnected native PDO handle receives its own registrations.
    public static function sum(Connection $connection, string $expression): string
    {
        return self::sqlite($connection)
            ? 'providentia_decimal_sum(' . $expression . ')'
            : 'SUM(' . $expression . ')';
    }

    public static function add(Connection $connection, string $left, string $right): string
    {
        return self::sqlite($connection)
            ? 'providentia_decimal_add(' . $left . ', ' . $right . ')'
            : '(' . $left . ' + ' . $right . ')';
    }

    public static function lessThan(Connection $connection, string $left, string $right): string
    {
        return self::sqlite($connection)
            ? 'providentia_decimal_less_than(' . $left . ', ' . $right . ')'
            : '(' . $left . ' < ' . $right . ')';
    }

    public static function greaterThan(Connection $connection, string $left, string $right): string
    {
        return self::sqlite($connection)
            ? 'providentia_decimal_greater_than(' . $left . ', ' . $right . ')'
            : '(' . $left . ' > ' . $right . ')';
    }

    public static function order(Connection $connection, string $expression): string
    {
        return self::sqlite($connection)
            ? $expression . ' COLLATE providentia_decimal'
            : $expression;
    }

    private static function sqlite(Connection $connection): bool
    {
        if (! $connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return false;
        }

        $native = $connection->getNativeConnection();
        if (! $native instanceof Sqlite) {
            throw new RuntimeException('Exact SQLite decimal SQL requires the PDO SQLite driver.');
        }
        if (self::$registered === null) {
            /** @var WeakMap<Sqlite, true> $registered */
            $registered = new WeakMap();
            self::$registered = $registered;
        }
        if (isset(self::$registered[$native])) {
            return true;
        }

        $sum = $native->createAggregate(
            'providentia_decimal_sum',
            static function (?string $total, int $row, mixed $value): ?string {
                if ($value === null) {
                    return $total;
                }
                return (string) self::decimal($total ?? '0')->plus(self::decimal($value));
            },
            static fn (?string $total, int $rows): ?string => $total,
            1,
        );
        $add = $native->createFunction(
            'providentia_decimal_add',
            static fn (mixed $left, mixed $right): ?string => $left === null || $right === null
                ? null
                : (string) self::decimal($left)->plus(self::decimal($right)),
            2,
            Sqlite::DETERMINISTIC,
        );
        $lessThan = $native->createFunction(
            'providentia_decimal_less_than',
            static fn (mixed $left, mixed $right): ?int => $left === null || $right === null
                ? null
                : (int) (self::decimal($left)->compareTo(self::decimal($right)) < 0),
            2,
            Sqlite::DETERMINISTIC,
        );
        $greaterThan = $native->createFunction(
            'providentia_decimal_greater_than',
            static fn (mixed $left, mixed $right): ?int => $left === null || $right === null
                ? null
                : (int) (self::decimal($left)->compareTo(self::decimal($right)) > 0),
            2,
            Sqlite::DETERMINISTIC,
        );
        // SQLite handles NULL ordering itself and invokes the collation only
        // for text values, preserving SQL's ascending/descending NULL behavior.
        $order = $native->createCollation(
            'providentia_decimal',
            static fn (string $left, string $right): int => self::decimal($left)->compareTo(self::decimal($right)),
        );
        if (! $sum || ! $add || ! $lessThan || ! $greaterThan || ! $order) {
            throw new RuntimeException('The SQLite decimal SQL functions could not be registered.');
        }
        self::$registered[$native] = true;
        return true;
    }

    private static function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of(DecimalProjection::string($value));
    }
}
