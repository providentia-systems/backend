<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Shopping;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;

final class LegacySuggestionPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function parityCases(): iterable
    {
        yield 'sufficient stock' => ['6', '2', 0];
        yield 'whole shortfall' => ['9', '1', 2];
        yield 'fractional shortfall rounds up' => ['1', '0', 1];
        yield 'fractional quantities stay deterministic' => ['7.5', '1.25', 2];
    }

    #[DataProvider('parityCases')]
    public function testLegacyParityFormula(string $purchases, string $stock, int $expected): void
    {
        self::assertSame(
            $expected,
            (new LegacySuggestionPolicy())->suggest($purchases, $stock),
        );
    }

    public function testNegativeEvidenceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LegacySuggestionPolicy())->suggest('-1', '0');
    }
}
