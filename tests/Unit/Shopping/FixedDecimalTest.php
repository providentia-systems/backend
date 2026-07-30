<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Shopping;

use PHPUnit\Framework\TestCase;
use Providentia\Shopping\Domain\FixedDecimal;

final class FixedDecimalTest extends TestCase
{
    public function testArithmeticDoesNotUseBinaryFloatingPoint(): void
    {
        $value = FixedDecimal::from('10.25')
            ->add(FixedDecimal::from('0.75'))
            ->subtract(FixedDecimal::from('1.1'));

        self::assertSame('9.9', $value->toString());
        self::assertSame('3.3', $value->divideByInt(3)->toString());
        self::assertSame(4, FixedDecimal::from('1')->ceilRatio(FixedDecimal::from('0.3')));
    }

    public function testDecimalDivisionIsDeterministic(): void
    {
        self::assertSame('2.5', FixedDecimal::from('10')->divide(FixedDecimal::from('4'))->toString());
        self::assertSame(
            '0.33333333',
            FixedDecimal::from('1')->divide(FixedDecimal::from('3'))->toString(),
        );
    }
}
