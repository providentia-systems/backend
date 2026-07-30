<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Shopping;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Shopping\Domain\PackOptimizer;

final class PackOptimizerTest extends TestCase
{
    public function testPackCountsAndCostsAreComparedWithinOneCurrency(): void
    {
        $ranked = (new PackOptimizer())->rank(
            '2',
            '1000',
            null,
            [
                $this->option('small', '500', '20', '2', 'NAD'),
                $this->option('large', '1000', '15', '1', 'NAD'),
            ],
            new DateTimeImmutable('2026-01-22T00:00:00+00:00'),
        );

        self::assertSame('large', $ranked[0]['packId']);
        self::assertSame(2, $ranked[0]['packCount']);
        self::assertSame('30', $ranked[0]['effectiveTotal']);
        self::assertTrue((bool) $ranked[0]['selected']);
    }

    public function testDifferentCurrenciesAreNeverImplicitlyCompared(): void
    {
        $ranked = (new PackOptimizer())->rank(
            '1',
            '1000',
            null,
            [
                $this->option('nad', '1000', '10', '1', 'NAD'),
                $this->option('zar', '1000', '5', '1', 'ZAR'),
            ],
            new DateTimeImmutable('2026-01-22T00:00:00+00:00'),
        );

        self::assertFalse((bool) $ranked[0]['selected']);
        self::assertFalse((bool) $ranked[1]['selected']);
        self::assertSame('currency-isolated-comparison', $ranked[0]['reason']);
    }

    /** @return array<string, mixed> */
    private function option(
        string $packId,
        string $packBase,
        string $lineTotal,
        string $quantity,
        string $currency,
    ): array {
        return [
            'packId' => $packId,
            'packBase' => $packBase,
            'storeId' => null,
            'currency' => $currency,
            'priceQuantity' => $quantity,
            'lineTotal' => $lineTotal,
            'observedAt' => '2026-01-20T00:00:00+00:00',
        ];
    }
}
