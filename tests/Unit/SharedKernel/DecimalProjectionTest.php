<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Infrastructure\Doctrine\DecimalProjection;
use UnexpectedValueException;

final class DecimalProjectionTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function decimals(): iterable
    {
        yield 'integer' => [2, '2'];
        yield 'fraction' => [2.125, '2.125'];
        yield 'smallest fraction' => [0.00000001, '0.00000001'];
        yield 'negative fraction' => [-0.00000001, '-0.00000001'];
        yield 'negative zero' => [-0.0, '0'];
        yield 'exact database precision' => ['999999999.99999999', '999999999.99999999'];
        yield 'money scale retained' => ['25.50000000', '25.50000000'];
    }

    #[DataProvider('decimals')]
    public function testDatabaseNumbersHavePlainDecimalStringRepresentations(mixed $value, string $expected): void
    {
        self::assertSame($expected, DecimalProjection::string($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'null' => [null];
        yield 'boolean' => [true];
        yield 'not a number' => [NAN];
        yield 'infinite' => [INF];
        yield 'invalid string' => ['not-a-decimal'];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidStoredValuesAreNotSilentlyCoerced(mixed $value): void
    {
        $this->expectException(UnexpectedValueException::class);
        DecimalProjection::string($value);
    }
}
