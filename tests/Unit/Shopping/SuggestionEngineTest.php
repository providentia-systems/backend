<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Shopping;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Shopping\Domain\SuggestionEngine;

final class SuggestionEngineTest extends TestCase
{
    public function testFormulaSeparatesDemandReserveAndFactualStock(): void
    {
        $result = (new SuggestionEngine())->suggest(
            [
                'factualStock' => '3',
                'minimumQuantity' => '2',
                'alwaysKeep' => false,
                'neverSuggest' => false,
                'preferredPackId' => null,
                'packId' => 'pack-1',
                'leadTimeDays' => 2,
                'targetCoverageDays' => null,
                'snoozeUntil' => null,
            ],
            [
                'dailyRate' => '0.5',
                'sampleIntervals' => 2,
                'confidenceScore' => '0.7000',
                'confidenceBand' => 'medium',
                'purchaseCadenceDays' => null,
                'nextExpectedShoppingAt' => null,
                'limitations' => [],
            ],
            14,
            new DateTimeImmutable('2026-01-22T00:00:00+00:00'),
        );

        self::assertTrue((bool) $result['eligible']);
        self::assertSame('8', $result['expectedDemand']);
        self::assertSame('2', $result['safetyStock']);
        self::assertSame('3', $result['factualStock']);
        self::assertSame('7', $result['requiredQuantity']);
    }

    public function testLowEvidenceFallsBackOnlyToConfiguredReserve(): void
    {
        $result = (new SuggestionEngine())->suggest(
            [
                'factualStock' => '0',
                'minimumQuantity' => '2',
                'alwaysKeep' => false,
                'neverSuggest' => false,
                'preferredPackId' => null,
                'packId' => null,
                'leadTimeDays' => 0,
                'targetCoverageDays' => null,
                'snoozeUntil' => null,
            ],
            [
                'dailyRate' => '0',
                'sampleIntervals' => 0,
                'confidenceScore' => '0.1000',
                'confidenceBand' => 'low',
                'purchaseCadenceDays' => null,
                'nextExpectedShoppingAt' => null,
                'limitations' => [],
            ],
            14,
            new DateTimeImmutable('2026-01-22T00:00:00+00:00'),
        );

        self::assertSame('0', $result['expectedDemand']);
        self::assertSame('2', $result['requiredQuantity']);
        self::assertStringContainsString('Insufficient history', implode(' ', $result['limitations']));
    }
}
