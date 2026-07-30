<?php

declare(strict_types=1);

namespace Providentia\Shopping\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Domain\ConsumptionEstimator;
use Providentia\Shopping\Domain\FixedDecimal;
use Providentia\Shopping\Domain\PackOptimizer;
use Providentia\Shopping\Domain\SuggestionEngine;

final class ShoppingIntelligenceService
{
    private const WRITERS = [
        HomeAuthorization::OWNER,
        HomeAuthorization::MANAGER,
        HomeAuthorization::MEMBER,
    ];
    private const POLICY_MANAGERS = [
        HomeAuthorization::OWNER,
        HomeAuthorization::MANAGER,
    ];

    public function __construct(
        private readonly ShoppingIntelligenceStore $store,
        private readonly HomeAuthorization $authorization,
        private readonly ConsumptionEstimator $estimator,
        private readonly SuggestionEngine $suggestions,
        private readonly PackOptimizer $packs,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return array<string, mixed> */
    public function generate(
        AuthenticatedIdentity $identity,
        string $homeId,
        int $horizonDays,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
        if ($horizonDays < 1 || $horizonDays > 90) {
            throw new Problem(422, 'Invalid suggestion horizon', 'Horizon must be between 1 and 90 days.');
        }
        $asOf = $this->clock->now();
        $inputs = $this->store->estimationInputs($homeId, $asOf);
        $counts = $this->groupEvidence($this->store->reliableCounts($homeId, $asOf));
        $inbound = $this->groupEvidence($this->store->inboundMovements($homeId, $asOf));
        $purchases = $this->groupEvidence($this->store->purchaseMovements($homeId, $asOf));
        $packOptions = $this->latestPackOptions($this->store->packOptions($homeId, $asOf));
        $watermark = $this->store->inputWatermark($homeId, $asOf);
        $estimateRows = [];
        $suggestionRows = [];
        foreach ($inputs as $input) {
            $homeProductId = (string) $input['homeProductId'];
            $estimate = $this->estimator->estimate(
                $this->countEvidence($counts[$homeProductId] ?? []),
                $this->movementEvidence($inbound[$homeProductId] ?? []),
                $this->purchaseEvidence($purchases[$homeProductId] ?? []),
                $asOf,
            );
            $estimate['id'] = $this->ids->generate();
            $estimate['homeProductId'] = $homeProductId;
            $estimateRows[] = $estimate;
            $suggestion = $this->suggestions->suggest($input, $estimate, $horizonDays, $asOf);
            if (($suggestion['eligible'] ?? false) !== true) {
                continue;
            }
            $suggestion['id'] = $this->ids->generate();
            $suggestion['homeProductId'] = $homeProductId;
            $suggestion['productName'] = (string) $input['productName'];
            $suggestion['packText'] = (string) ($input['packText'] ?? '');
            $suggestion['packOptions'] = [];
            if (
                $input['currentPackBase'] !== null
                && isset($packOptions[$homeProductId])
            ) {
                $ranked = $this->packs->rank(
                    (string) $suggestion['requiredQuantity'],
                    (string) $input['currentPackBase'],
                    $input['preferredPackId'] === null ? null : (string) $input['preferredPackId'],
                    $packOptions[$homeProductId],
                    $asOf,
                );
                $suggestion['packOptions'] = $this->withOptionIds($ranked);
                foreach ($suggestion['packOptions'] as $option) {
                    if (($option['selected'] ?? false) === true) {
                        $suggestion['selectedPackId'] = $option['packId'];
                        $suggestion['packCount'] = $option['packCount'];
                        break;
                    }
                }
                if (count(array_unique(array_column($ranked, 'currency'))) > 1) {
                    $suggestion['limitations'][] =
                        'Prices in different currencies are shown separately and never compared.';
                }
            }
            $suggestionRows[] = $suggestion;
        }
        $estimateRunId = $this->ids->generate();
        $suggestionRunId = $this->ids->generate();
        $auditEventId = $this->ids->generate();
        $this->transactions->transactional(function () use (
            $estimateRunId,
            $suggestionRunId,
            $auditEventId,
            $homeId,
            $identity,
            $horizonDays,
            $watermark,
            $estimateRows,
            $suggestionRows,
            $asOf,
        ): void {
            $this->store->saveRun(
                $estimateRunId,
                $suggestionRunId,
                $homeId,
                $identity->userId,
                $auditEventId,
                $horizonDays,
                $watermark,
                $estimateRows,
                $suggestionRows,
                $asOf,
            );
        });

        return [
            'id' => $suggestionRunId,
            'estimateRunId' => $estimateRunId,
            'modelVersion' => SuggestionEngine::VERSION,
            'methodVersion' => ConsumptionEstimator::VERSION,
            'asOf' => $asOf->format(DATE_ATOM),
            'inputWatermark' => $watermark,
            'estimatedProducts' => count($estimateRows),
            'suggestionCount' => count($suggestionRows),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function estimates(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requireMember($identity, $homeId);

        return $this->store->latestEstimates($homeId);
    }

    /** @return list<array<string, mixed>> */
    public function suggestions(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requireMember($identity, $homeId);

        return $this->store->latestSuggestions($homeId, $this->clock->now());
    }

    /** @return list<array<string, mixed>> */
    public function priceComparisons(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requireMember($identity, $homeId);

        return $this->store->latestPriceComparisons($homeId);
    }

    /** @return array<string, mixed> */
    public function explanation(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $suggestionId,
    ): array {
        $this->authorization->requireMember($identity, $homeId);
        $result = $this->store->explanation($homeId, $suggestionId);
        if ($result === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function preference(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $homeProductId,
    ): array {
        $this->authorization->requireMember($identity, $homeId);

        return $this->store->preference($homeId, $homeProductId) ?? [
            'homeProductId' => $homeProductId,
            'minimumQuantity' => null,
            'alwaysKeep' => false,
            'neverSuggest' => false,
            'preferredPackId' => null,
            'leadTimeDays' => 0,
            'targetCoverageDays' => null,
            'snoozeUntil' => null,
            'revision' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{revision: int}
     */
    public function putPreference(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $homeProductId,
        array $input,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::POLICY_MANAGERS);
        $minimum = $this->optionalQuantity($input['minimumQuantity'] ?? null);
        $leadTime = (int) ($input['leadTimeDays'] ?? 0);
        $coverageValue = $input['targetCoverageDays'] ?? null;
        $coverage = $coverageValue === null ? null : (int) $coverageValue;
        $snoozeValue = $input['snoozeUntil'] ?? null;
        $snooze = $snoozeValue === null ? null : trim((string) $snoozeValue);
        $snoozeDate = $snooze === null ? null : $this->parseDate($snooze);
        $preferredValue = $input['preferredPackId'] ?? null;
        $preferred = $preferredValue === null ? null : trim((string) $preferredValue);
        $expectedRevision = (int) ($input['expectedRevision'] ?? -1);
        if (
            $leadTime < 0
            || $leadTime > 365
            || ($coverage !== null && ($coverage < 1 || $coverage > 365))
            || ($snooze !== null && $snoozeDate === null)
            || $expectedRevision < 0
        ) {
            throw new Problem(422, 'Invalid replenishment policy', 'Policy values are outside supported bounds.');
        }
        $at = $this->clock->now();
        try {
            $saved = $this->transactions->transactional(fn (): bool => $this->store->savePreference(
                $homeId,
                $homeProductId,
                $identity->userId,
                $this->ids->generate(),
                $this->ids->generate(),
                [
                    'minimumQuantity' => $minimum,
                    'alwaysKeep' => (bool) ($input['alwaysKeep'] ?? false),
                    'neverSuggest' => (bool) ($input['neverSuggest'] ?? false),
                    'preferredPackId' => $preferred === '' ? null : $preferred,
                    'leadTimeDays' => $leadTime,
                    'targetCoverageDays' => $coverage,
                    'snoozeUntil' => $snooze,
                ],
                $expectedRevision,
                $at,
            ));
        } catch (\DomainException $error) {
            throw new Problem(422, 'Invalid replenishment policy', $error->getMessage());
        }
        if (! $saved) {
            throw new Problem(409, 'Revision conflict', 'The replenishment policy changed.');
        }

        return ['revision' => $expectedRevision + 1];
    }

    /** @return array{id: string} */
    public function feedback(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $suggestionId,
        string $decision,
        ?string $resultQuantity,
        string $reason,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::WRITERS);
        if (! in_array($decision, ['accepted', 'edited', 'dismissed', 'snoozed'], true)) {
            throw new Problem(422, 'Invalid suggestion feedback', 'Feedback decision is not supported.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new Problem(422, 'Invalid suggestion feedback', 'A concise reason is required.');
        }
        $suggestion = $this->store->suggestion($homeId, $suggestionId);
        if ($suggestion === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        $quantity = $resultQuantity === null ? null : $this->optionalQuantity($resultQuantity);
        if ($decision === 'edited' && $quantity === null) {
            throw new Problem(422, 'Invalid suggestion feedback', 'Edited feedback requires a quantity.');
        }
        $id = $this->ids->generate();
        $auditEventId = $this->ids->generate();
        $at = $this->clock->now();
        $this->transactions->transactional(function () use (
            $id,
            $homeId,
            $suggestionId,
            $identity,
            $auditEventId,
            $decision,
            $suggestion,
            $quantity,
            $reason,
            $at,
        ): void {
            $this->store->recordFeedback(
                $id,
                $homeId,
                $suggestionId,
                $identity->userId,
                $auditEventId,
                $decision,
                (string) $suggestion['requiredQuantity'],
                $quantity,
                $reason,
                $at,
            );
        });

        return ['id' => $id];
    }

    /**
     * @param list<string> $cutoffs
     * @return array<string, mixed>
     */
    public function backtest(
        AuthenticatedIdentity $identity,
        string $homeId,
        array $cutoffs,
        int $evaluationDays,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::POLICY_MANAGERS);
        if ($cutoffs === [] || count($cutoffs) > 12 || $evaluationDays < 1 || $evaluationDays > 90) {
            throw new Problem(422, 'Invalid backtest', 'Provide one to twelve cutoffs and 1 to 90 evaluation days.');
        }
        $now = $this->clock->now();
        $parsed = [];
        foreach (array_values(array_unique($cutoffs)) as $cutoff) {
            $date = $this->parseDate($cutoff);
            if ($date === null || $date >= $now || $date->modify('+' . $evaluationDays . ' days') > $now) {
                throw new Problem(422, 'Invalid backtest', 'Every evaluation window must be fully historical.');
            }
            $parsed[] = $date;
        }
        $results = [];
        $truePositive = 0;
        $falsePositive = 0;
        $falseNegative = 0;
        foreach ($parsed as $cutoff) {
            $inputs = $this->store->estimationInputs($homeId, $cutoff);
            $counts = $this->groupEvidence($this->store->reliableCounts($homeId, $cutoff));
            $inbound = $this->groupEvidence($this->store->inboundMovements($homeId, $cutoff));
            $purchases = $this->groupEvidence($this->store->purchaseMovements($homeId, $cutoff));
            $future = array_fill_keys(array_map(
                static fn (array $row): string => (string) $row['homeProductId'],
                $this->store->purchasesBetween(
                    $homeId,
                    $cutoff,
                    $cutoff->modify('+' . $evaluationDays . ' days'),
                ),
            ), true);
            foreach ($inputs as $input) {
                $homeProductId = (string) $input['homeProductId'];
                $estimate = $this->estimator->estimate(
                    $this->countEvidence($counts[$homeProductId] ?? []),
                    $this->movementEvidence($inbound[$homeProductId] ?? []),
                    $this->purchaseEvidence($purchases[$homeProductId] ?? []),
                    $cutoff,
                );
                $suggestion = $this->suggestions->suggest($input, $estimate, 14, $cutoff);
                $predicted = ($suggestion['eligible'] ?? false) === true;
                $purchased = isset($future[$homeProductId]);
                $truePositive += $predicted && $purchased ? 1 : 0;
                $falsePositive += $predicted && ! $purchased ? 1 : 0;
                $falseNegative += ! $predicted && $purchased ? 1 : 0;
                $results[] = [
                    'id' => $this->ids->generate(),
                    'cutoffAt' => $cutoff,
                    'homeProductId' => $homeProductId,
                    'suggested' => $predicted,
                    'purchasedLater' => $purchased,
                    'suggestedQuantity' => $predicted
                        ? (string) $suggestion['requiredQuantity']
                        : '0',
                    'confidenceBand' => (string) $estimate['confidenceBand'],
                ];
            }
        }
        $support = $truePositive + $falsePositive;
        $actualPositive = $truePositive + $falseNegative;
        $feedback = $this->store->feedbackSummary($homeId, $now);
        $metrics = [
            'truePositive' => $truePositive,
            'falsePositive' => $falsePositive,
            'falseNegative' => $falseNegative,
            'precision' => $support === 0 ? 'unavailable' : number_format($truePositive / $support, 4, '.', ''),
            'recall' => $actualPositive === 0
                ? 'unavailable'
                : number_format($truePositive / $actualPositive, 4, '.', ''),
            'support' => $support,
            'evaluatedRows' => count($results),
            'missedStockOutsProxy' => $falseNegative,
            'overbuyingProxy' => $falsePositive,
            'feedbackCount' => $feedback['total'],
            'userOverrideRate' => $feedback['total'] === 0
                ? 'unavailable'
                : number_format($feedback['overrides'] / $feedback['total'], 4, '.', ''),
        ];
        $limitations = [
            'A later purchase is a replenishment proxy, not proof of a stock-out.',
            'A suggestion without a later purchase is an overbuying signal, not proof of overbuying.',
            'No fact after each cutoff is used to build its suggestion.',
            'The limited count history may make precision unavailable or weak.',
        ];
        $id = $this->ids->generate();
        $auditEventId = $this->ids->generate();
        $this->transactions->transactional(function () use (
            $id,
            $auditEventId,
            $homeId,
            $identity,
            $parsed,
            $evaluationDays,
            $results,
            $metrics,
            $limitations,
            $now,
        ): void {
            $this->store->saveBacktest(
                $id,
                $homeId,
                $identity->userId,
                $auditEventId,
                array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), $parsed),
                $evaluationDays,
                $results,
                $metrics,
                $limitations,
                $now,
            );
        });

        return ['id' => $id, 'metrics' => $metrics, 'limitations' => $limitations];
    }

    /** @return array<string, mixed> */
    public function backtestResult(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $runId,
    ): array {
        $this->authorization->requireRole($identity, $homeId, self::POLICY_MANAGERS);
        $result = $this->store->backtest($homeId, $runId);
        if ($result === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupEvidence(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['homeProductId']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{at: DateTimeImmutable, quantity: string}>
     */
    private function countEvidence(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'at' => new DateTimeImmutable((string) $row['occurredAt']),
                'quantity' => (string) $row['quantity'],
            ],
            $rows,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{at: DateTimeImmutable, quantity: string}>
     */
    private function movementEvidence(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'at' => new DateTimeImmutable((string) $row['occurredAt']),
                'quantity' => (string) $row['quantity'],
            ],
            $rows,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<DateTimeImmutable>
     */
    private function purchaseEvidence(array $rows): array
    {
        return array_map(
            static fn (array $row): DateTimeImmutable => new DateTimeImmutable(
                (string) $row['occurredAt'],
            ),
            $rows,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function latestPackOptions(array $rows): array
    {
        $grouped = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = implode('|', [
                (string) $row['homeProductId'],
                (string) $row['packId'],
                (string) ($row['storeId'] ?? ''),
                (string) $row['currency'],
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $grouped[(string) $row['homeProductId']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withOptionIds(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id'] = $this->ids->generate();
        }
        unset($row);

        return $rows;
    }

    private function optionalQuantity(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $quantity = FixedDecimal::from((string) $value);
        } catch (InvalidArgumentException $error) {
            throw new Problem(422, 'Invalid quantity', $error->getMessage());
        }
        if ($quantity->compare(FixedDecimal::zero()) < 0) {
            throw new Problem(422, 'Invalid quantity', 'Quantity cannot be negative.');
        }

        return $quantity->toString();
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || (
                is_array($errors)
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
            )
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $date;
    }
}
