<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Inventory;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Inventory\Domain\DecimalQuantity;

final class DecimalQuantityTest extends TestCase
{
    /** @return iterable<string, array{string|int, string}> */
    public static function canonicalQuantities(): iterable
    {
        yield 'integer' => [7, '7'];
        yield 'fraction' => ['12.34000000', '12.34'];
        yield 'sub-unit' => ['0.00000001', '0.00000001'];
        yield 'negative delta' => ['-3.25000000', '-3.25'];
    }

    #[DataProvider('canonicalQuantities')]
    public function testDeltaUsesExactFixedPointArithmetic(string|int $input, string $expected): void
    {
        self::assertSame($expected, DecimalQuantity::delta($input)->toString());
    }

    public function testAddAndSubtractDoNotUseFloatingPointArithmetic(): void
    {
        $value = DecimalQuantity::delta('0.1')
            ->add(DecimalQuantity::delta('0.2'))
            ->subtract(DecimalQuantity::delta('0.05'));

        self::assertSame('0.25', $value->toString());
    }

    public function testPhysicalQuantityRejectsNegativeValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalQuantity::quantity('-0.00000001');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDecimals(): iterable
    {
        yield 'float syntax' => ['1e3'];
        yield 'leading zero' => ['01'];
        yield 'too precise' => ['0.000000001'];
        yield 'too large' => ['1000000000'];
        yield 'not numeric' => ['one'];
    }

    #[DataProvider('invalidDecimals')]
    public function testInvalidOrAmbiguousDecimalSyntaxIsRejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalQuantity::delta($value);
    }
}
