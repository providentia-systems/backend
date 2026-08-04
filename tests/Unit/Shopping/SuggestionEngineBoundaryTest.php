<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Shopping;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Shopping\Domain\SuggestionEngine;

final class SuggestionEngineBoundaryTest extends TestCase
{
    private DateTimeImmutable $asOf;

    protected function setUp(): void
    {
        $this->asOf = new DateTimeImmutable('2026-01-22T00:00:00+00:00');
    }

    public function testNeverSuggestAndSnoozeBoundariesReturnTheSpecificReason(): void
    {
        $engine = new SuggestionEngine();
        self::assertSame(
            ['eligible' => false, 'reason' => 'never-suggest preference'],
            $engine->suggest(
                [...$this->product(), 'neverSuggest' => true],
                $this->estimate(),
                14,
                $this->asOf,
            ),
        );
        self::assertSame(
            ['eligible' => false, 'reason' => 'snoozed preference'],
            $engine->suggest(
                [...$this->product(), 'snoozeUntil' => '2026-01-22'],
                $this->estimate(),
                14,
                $this->asOf,
            ),
        );

        $yesterday = $engine->suggest(
            [...$this->product(), 'snoozeUntil' => '2026-01-21'],
            $this->estimate(),
            14,
            $this->asOf,
        );
        self::assertArrayHasKey('requiredQuantity', $yesterday);

        $nonString = $engine->suggest(
            [...$this->product(), 'snoozeUntil' => 20_260_122],
            $this->estimate(),
            14,
            $this->asOf,
        );
        self::assertArrayHasKey('requiredQuantity', $nonString);
    }

    public function testConfiguredCadenceAndDefaultHorizonsHaveExactCapsAndFloors(): void
    {
        $engine = new SuggestionEngine();
        $configured = $engine->suggest(
            [...$this->product(), 'targetCoverageDays' => 365, 'leadTimeDays' => 10],
            $this->estimate(),
            14,
            $this->asOf,
        );
        self::assertSame(365, $configured['horizonDays']);
        self::assertSame('365', $configured['expectedDemand']);

        $cadence = $engine->suggest(
            $this->product(),
            [...$this->estimate(), 'nextExpectedShoppingAt' => $this->asOf->modify('+36 hours')],
            14,
            $this->asOf,
        );
        self::assertSame(2, $cadence['horizonDays']);
        self::assertSame('2', $cadence['expectedDemand']);

        $default = $engine->suggest($this->product(), $this->estimate(), 14, $this->asOf);
        self::assertSame(14, $default['horizonDays']);

        $floor = $engine->suggest(
            [...$this->product(), 'leadTimeDays' => -5],
            $this->estimate(),
            0,
            $this->asOf,
        );
        self::assertSame(1, $floor['horizonDays']);
        self::assertSame('1', $floor['expectedDemand']);
    }

    public function testStockReserveAndNoEvidenceRulesRemainIndependent(): void
    {
        $engine = new SuggestionEngine();
        $negative = $engine->suggest(
            [
                ...$this->product(),
                'factualStock' => '-2',
                'minimumQuantity' => '-3',
                'targetCoverageDays' => 1,
            ],
            $this->estimate(),
            14,
            $this->asOf,
        );
        self::assertSame('-2', $negative['factualStock']);
        self::assertSame('0', $negative['usableStock']);
        self::assertSame('0', $negative['safetyStock']);
        self::assertSame('1', $negative['requiredQuantity']);

        $alwaysKeep = $engine->suggest(
            [...$this->product(), 'alwaysKeep' => true],
            [
                ...$this->estimate(),
                'dailyRate' => '99',
                'sampleIntervals' => 0,
                'limitations' => ['existing limitation'],
            ],
            14,
            $this->asOf,
        );
        self::assertSame('0', $alwaysKeep['expectedDemand']);
        self::assertSame('1', $alwaysKeep['safetyStock']);
        self::assertSame('1', $alwaysKeep['requiredQuantity']);
        self::assertContains('existing limitation', $alwaysKeep['limitations']);
        self::assertStringContainsString('Insufficient history', implode(' ', $alwaysKeep['limitations']));

        $positiveMinimum = $engine->suggest(
            [...$this->product(), 'alwaysKeep' => true, 'minimumQuantity' => '2'],
            [...$this->estimate(), 'dailyRate' => '0'],
            14,
            $this->asOf,
        );
        self::assertSame('2', $positiveMinimum['safetyStock']);
    }

    public function testCompleteExplanationAndPackRoundingAreStable(): void
    {
        $next = new DateTimeImmutable('2026-01-27T12:00:00+00:00');
        $result = (new SuggestionEngine())->suggest(
            [
                ...$this->product(),
                'targetCoverageDays' => 5,
                'preferredPackId' => 'preferred-pack',
                'packId' => 'fallback-pack',
            ],
            [
                ...$this->estimate(),
                'dailyRate' => '0.25',
                'purchaseCadenceDays' => 7,
                'nextExpectedShoppingAt' => $next,
                'limitations' => ['same', 'same'],
            ],
            14,
            $this->asOf,
        );

        self::assertTrue($result['eligible']);
        self::assertSame('1.25', $result['requiredQuantity']);
        self::assertSame('preferred-pack', $result['selectedPackId']);
        self::assertSame(2, $result['packCount']);
        self::assertSame(['same'], $result['limitations']);
        self::assertSame([
            ['key' => 'expected-demand', 'value' => '1.25', 'days' => 5],
            [
                'key' => 'purchase-cadence',
                'days' => 7,
                'nextExpectedShoppingAt' => $next->format(DATE_ATOM),
            ],
            ['key' => 'minimum-reserve', 'value' => '0'],
            ['key' => 'factual-stock', 'value' => '0'],
            ['key' => 'required-quantity', 'value' => '1.25'],
        ], $result['factors']);
        self::assertSame('0.7000', $result['confidenceScore']);
        self::assertSame('medium', $result['confidenceBand']);
    }

    public function testZeroRequirementIsIneligibleAndUsesFallbackPack(): void
    {
        $result = (new SuggestionEngine())->suggest(
            [...$this->product(), 'factualStock' => '100', 'packId' => 'fallback-pack'],
            $this->estimate(),
            14,
            $this->asOf,
        );

        self::assertFalse($result['eligible']);
        self::assertSame('0', $result['requiredQuantity']);
        self::assertSame(0, $result['packCount']);
        self::assertSame('fallback-pack', $result['selectedPackId']);
    }

    /** @return array<string, mixed> */
    private function product(): array
    {
        return [
            'factualStock' => '0',
            'minimumQuantity' => null,
            'alwaysKeep' => false,
            'neverSuggest' => false,
            'preferredPackId' => null,
            'packId' => null,
            'leadTimeDays' => 0,
            'targetCoverageDays' => null,
            'snoozeUntil' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function estimate(): array
    {
        return [
            'dailyRate' => '1',
            'sampleIntervals' => 1,
            'confidenceScore' => '0.7000',
            'confidenceBand' => 'medium',
            'purchaseCadenceDays' => null,
            'nextExpectedShoppingAt' => null,
            'limitations' => [],
        ];
    }
}
