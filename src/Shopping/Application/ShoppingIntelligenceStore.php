<?php

declare(strict_types=1);

namespace Providentia\Shopping\Application;

use DateTimeImmutable;

interface ShoppingIntelligenceStore extends ShoppingIntelligenceReader
{
    /** @return list<array<string, mixed>> */
    public function estimationInputs(string $homeId, DateTimeImmutable $asOf): array;

    /** @return list<array<string, mixed>> */
    public function reliableCounts(string $homeId, DateTimeImmutable $asOf): array;

    /** @return list<array<string, mixed>> */
    public function inboundMovements(string $homeId, DateTimeImmutable $asOf): array;

    /** @return list<array<string, mixed>> */
    public function purchaseMovements(string $homeId, DateTimeImmutable $asOf): array;

    /** @return list<array<string, mixed>> */
    public function packOptions(string $homeId, DateTimeImmutable $asOf): array;

    public function inputWatermark(string $homeId, DateTimeImmutable $asOf): string;

    /**
     * @param list<array<string, mixed>> $estimates
     * @param list<array<string, mixed>> $suggestions
     */
    public function saveRun(
        string $estimateRunId,
        string $suggestionRunId,
        string $homeId,
        string $actorUserId,
        string $auditEventId,
        int $horizonDays,
        string $watermark,
        array $estimates,
        array $suggestions,
        DateTimeImmutable $asOf,
    ): void;

    /** @return list<array<string, mixed>> */
    public function latestEstimates(string $homeId): array;

    /** @return list<array<string, mixed>> */
    public function latestSuggestions(string $homeId, DateTimeImmutable $asOf): array;

    /** @return list<array<string, mixed>> */
    public function latestPriceComparisons(string $homeId): array;

    /** @return array<string, mixed>|null */
    public function explanation(string $homeId, string $suggestionId): ?array;

    /** @return array<string, mixed>|null */
    public function preference(string $homeId, string $homeProductId): ?array;

    /** @param array<string, mixed> $preference */
    public function savePreference(
        string $homeId,
        string $homeProductId,
        string $actorUserId,
        string $auditEventId,
        string $revisionRecordId,
        array $preference,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool;

    /** @return array<string, mixed>|null */
    public function suggestion(string $homeId, string $suggestionId): ?array;

    public function recordFeedback(
        string $id,
        string $homeId,
        string $suggestionId,
        string $actorUserId,
        string $auditEventId,
        string $decision,
        string $originalQuantity,
        ?string $resultQuantity,
        string $reason,
        DateTimeImmutable $at,
    ): void;

    /** @return list<array<string, mixed>> */
    public function purchasesBetween(
        string $homeId,
        DateTimeImmutable $after,
        DateTimeImmutable $through,
    ): array;

    /** @return array{total: int, overrides: int} */
    public function feedbackSummary(string $homeId, DateTimeImmutable $asOf): array;

    /**
     * @param list<string> $cutoffs
     * @param list<array<string, mixed>> $results
     * @param array<string, int|float|string> $metrics
     * @param list<string> $limitations
     */
    public function saveBacktest(
        string $id,
        string $homeId,
        string $actorUserId,
        string $auditEventId,
        array $cutoffs,
        int $evaluationDays,
        array $results,
        array $metrics,
        array $limitations,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, mixed>|null */
    public function backtest(string $homeId, string $runId): ?array;
}
