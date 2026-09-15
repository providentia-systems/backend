<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Infrastructure\Doctrine\AiSqlIntegerProjection;
use UnexpectedValueException;

final class AiSqlIntegerProjectionTest extends TestCase
{
    public function testOnlyDocumentedIntegerColumnsAreNormalized(): void
    {
        self::assertSame([
            'revision' => 3,
            'keyVersion' => 2,
            'estimatedCostMicros' => 0,
            'maxAttempts' => 1,
            'maxTotalTokens' => 25000,
            'maxEstimatedCostMicros' => 100000,
            'id' => '000123',
            'model' => '007',
            'payload' => ['quantity' => '1.500'],
        ], AiSqlIntegerProjection::normalize([
            'revision' => '3',
            'keyVersion' => '2',
            'estimatedCostMicros' => '0',
            'maxAttempts' => '1',
            'maxTotalTokens' => '25000',
            'maxEstimatedCostMicros' => '100000',
            'id' => '000123',
            'model' => '007',
            'payload' => ['quantity' => '1.500'],
        ]));
    }

    public function testNativeIntegersAndNullableCredentialVersionArePreserved(): void
    {
        $row = ['revision' => 1, 'keyVersion' => null, 'estimatedCostMicros' => 0];
        self::assertSame($row, AiSqlIntegerProjection::normalize($row));
        self::assertSame(['id' => 'profile'], AiSqlIntegerProjection::normalize(['id' => 'profile']));
        self::assertSame(['revision' => PHP_INT_MAX], AiSqlIntegerProjection::normalize([
            'revision' => (string) PHP_INT_MAX,
        ]));
    }

    #[DataProvider('invalidIntegers')]
    public function testMalformedValuesAreNotCoerced(mixed $value): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The persisted AI metadata contains an invalid integer.');
        AiSqlIntegerProjection::normalize(['revision' => $value]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidIntegers(): iterable
    {
        yield 'null revision' => [null];
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'float' => [1.0];
        yield 'fraction' => [1.5];
        yield 'empty' => [''];
        yield 'whitespace' => [' 1'];
        yield 'suffix' => ['1x'];
        yield 'exponent' => ['1e2'];
        yield 'decimal' => ['1.0'];
        yield 'plus sign' => ['+1'];
        yield 'leading zero' => ['01'];
        yield 'negative' => [-1];
        yield 'negative string' => ['-1'];
        yield 'zero revision' => [0];
        yield 'zero string revision' => ['0'];
        yield 'overflow' => [(string) PHP_INT_MAX . '0'];
        yield 'array' => [[]];
    }

    public function testNegativeCostDoesNotBecomeAFreeProvider(): void
    {
        $this->expectException(UnexpectedValueException::class);
        AiSqlIntegerProjection::normalize(['estimatedCostMicros' => '-1']);
    }
}
