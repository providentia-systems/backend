<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Shopping;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomePermissionAuthorizer;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingIntelligenceService;
use Providentia\Shopping\Application\ShoppingIntelligenceStore;
use Providentia\Shopping\Domain\ConsumptionEstimator;
use Providentia\Shopping\Domain\PackOptimizer;
use Providentia\Shopping\Domain\SuggestionEngine;
use RuntimeException;

final class RecommendationDiagnosticsTest extends TestCase
{
    public function testGenerationExplainsEveryEmptyResultAndKeepsMinimumFallback(): void
    {
        $store = $this->createMock(ShoppingIntelligenceStore::class);
        $store->method('estimationInputs')->willReturn([
            $this->product('minimum', ['minimumQuantity' => '3']),
            $this->product('unconfigured'),
            $this->product('sufficient', ['minimumQuantity' => '1']),
            $this->product('excluded', ['neverSuggest' => true]),
            $this->product('paused', ['snoozeUntil' => '2026-10-30']),
            $this->product('uncertain', ['stockUncertain' => true]),
        ]);
        foreach (['reliableCounts', 'inboundMovements', 'purchaseMovements', 'packOptions'] as $method) {
            $store->method($method)->willReturn([]);
        }
        $store->method('inputWatermark')->willReturn('accepted-server-watermark');
        $store->expects(self::once())->method('saveRun')->willReturnCallback(
            /** @param list<array<string, mixed>> $estimates @param list<array<string, mixed>> $suggestions */
            static function (
                string $estimateRunId,
                string $suggestionRunId,
                string $homeId,
                string $actorUserId,
                string $auditEventId,
                int $horizonDays,
                string $watermark,
                array $estimates,
                array $suggestions,
            ): void {
                self::assertCount(6, $estimates);
                self::assertCount(1, $suggestions);
                self::assertSame('minimum', $suggestions[0]['homeProductId']);
                self::assertSame('2', $suggestions[0]['requiredQuantity']);
                self::assertSame('minimum_reserve_only', $suggestions[0]['calculationBasis']);
            },
        );
        $result = $this->service($store)->generate(
            new AuthenticatedIdentity('actor', 'session', 'device', 'home', []),
            'home',
            14,
        );

        self::assertSame('completed', $result['status']);
        self::assertSame(1, $result['suggestionCount']);
        self::assertSame('accepted_server_evidence', $result['inputScope']);
        self::assertSame([
            'usage_shortfall' => 0,
            'minimum_reserve_shortfall' => 1,
            'insufficient_usage_evidence' => 1,
            'sufficient_stock' => 1,
            'excluded' => 1,
            'paused' => 1,
            'stock_chronology_uncertain' => 1,
        ], $result['reasonCounts']);
        self::assertNull($result['diagnostics'][1]['minimumQuantity']);
        self::assertFalse($result['diagnostics'][1]['usageEvidenceAvailable']);
        self::assertTrue($result['diagnostics'][5]['stockUncertain']);
    }

    public function testFailedPersistenceNeverReturnsACompletedRun(): void
    {
        $store = $this->createMock(ShoppingIntelligenceStore::class);
        $methods = ['estimationInputs', 'reliableCounts', 'inboundMovements', 'purchaseMovements', 'packOptions'];
        foreach ($methods as $method) {
            $store->method($method)->willReturn([]);
        }
        $store->method('inputWatermark')->willReturn('watermark');
        $store->expects(self::once())->method('saveRun')
            ->willThrowException(new RuntimeException('Persistence unavailable.'));

        $this->expectException(RuntimeException::class);
        $this->service($store)->generate(
            new AuthenticatedIdentity('actor', 'session', 'device', 'home', []),
            'home',
            14,
        );
    }

    public function testReadPermissionCanRefreshDerivedResultsWithoutRequiringShoppingWrite(): void
    {
        $store = $this->createMock(ShoppingIntelligenceStore::class);
        $store->method('latestRun')->willReturn([
            'id' => 'old-run', 'horizonDays' => 14, 'inputWatermark' => 'old-watermark',
        ]);
        $methods = ['estimationInputs', 'reliableCounts', 'inboundMovements', 'purchaseMovements', 'packOptions'];
        foreach ($methods as $method) {
            $store->method($method)->willReturn([]);
        }
        $store->method('latestSuggestions')->willReturn([]);
        $store->method('inputWatermark')->willReturn('new-watermark');
        $store->expects(self::once())->method('saveRun');
        $store->expects(self::once())->method('lockGeneration')->with('home');
        $authorization = $this->createMock(HomePermissionAuthorizer::class);
        $authorization->expects(self::once())->method('requirePermission')
            ->with(self::anything(), 'home', HomePermission::SHOPPING_READ)->willReturn([]);

        self::assertSame([], $this->service($store, $authorization)->suggestions(
            new AuthenticatedIdentity('actor', 'session', 'device', 'home', []),
            'home',
        ));
    }

    public function testConcurrentEvidenceChangesNeverPublishAMixedCalculation(): void
    {
        foreach ([['original', 'changed'], ['original', 'original', 'changed']] as $watermarks) {
            $store = $this->createMock(ShoppingIntelligenceStore::class);
            $methods = ['estimationInputs', 'reliableCounts', 'inboundMovements', 'purchaseMovements', 'packOptions'];
            foreach ($methods as $method) {
                $store->method($method)->willReturn([]);
            }
            $store->method('inputWatermark')->willReturnOnConsecutiveCalls(...$watermarks);
            $store->expects(self::never())->method('saveRun');
            try {
                $this->service($store)->generate(
                    new AuthenticatedIdentity('actor', 'session', 'device', 'home', []),
                    'home',
                    14,
                );
                self::fail('Concurrent source changes must invalidate the calculation.');
            } catch (Problem $problem) {
                self::assertSame(409, $problem->status);
                self::assertSame('urn:providentia:shopping:inputs-changed', $problem->type);
            }
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function product(string $id, array $overrides = []): array
    {
        return [
            'homeProductId' => $id, 'productName' => $id, 'factualStock' => '1',
            'minimumQuantity' => null, 'alwaysKeep' => false, 'neverSuggest' => false,
            'currentPackBase' => null, 'preferredPackId' => null,
            ...$overrides,
        ];
    }

    private function service(
        ShoppingIntelligenceStore $store,
        ?HomePermissionAuthorizer $authorization = null,
    ): ShoppingIntelligenceService {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-10-22T12:00:00Z'));
        $ids = $this->createStub(UuidGenerator::class);
        $number = 0;
        $ids->method('generate')->willReturnCallback(static function () use (&$number): string {
            return 'id-' . ++$number;
        });
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work());

        return new ShoppingIntelligenceService(
            $store,
            $authorization ?? $this->createStub(HomePermissionAuthorizer::class),
            new ConsumptionEstimator(),
            new SuggestionEngine(),
            new PackOptimizer(),
            $ids,
            $clock,
            $transactions,
        );
    }
}
