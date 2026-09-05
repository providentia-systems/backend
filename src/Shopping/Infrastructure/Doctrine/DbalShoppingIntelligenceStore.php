<?php

declare(strict_types=1);

namespace Providentia\Shopping\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use DomainException;
use Providentia\Shopping\Application\ShoppingIntelligenceStore;
use Providentia\Shopping\Domain\ConsumptionEstimator;
use Providentia\Shopping\Domain\SuggestionEngine;

final class DbalShoppingIntelligenceStore implements ShoppingIntelligenceStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function estimationInputs(string $homeId, DateTimeImmutable $asOf): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT hp.id AS homeProductId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    COALESCE(pk.original_pack_text, hp.original_pack_text, :empty) AS packText,
                    hp.pack_id AS packId,
                    pk.normalized_base_amount AS currentPackBase,
                    COALESCE(
                        (SELECT SUM(sm.quantity_delta)
                         FROM stock_movements sm
                         WHERE sm.home_id = hp.home_id
                           AND sm.home_product_id = hp.id
                           AND sm.occurred_at <= :as_of
                           AND sm.created_at <= :as_of),
                        0
                    ) AS factualStock,
                    COALESCE(pref_history.minimumQuantity, sp.minimum_quantity) AS minimumQuantity,
                    COALESCE(pref_history.alwaysKeep, sp.always_keep, 0) AS alwaysKeep,
                    COALESCE(pref_history.neverSuggest, sp.never_suggest, 0) AS neverSuggest,
                    COALESCE(pref_history.preferredPackId, sp.preferred_pack_id) AS preferredPackId,
                    COALESCE(pref_history.leadTimeDays, sp.lead_time_days, 0) AS leadTimeDays,
                    COALESCE(pref_history.targetCoverageDays, sp.target_coverage_days) AS targetCoverageDays,
                    COALESCE(pref_history.snoozeUntil, sp.snooze_until) AS snoozeUntil
             FROM home_products hp
             LEFT JOIN products p ON p.id = hp.product_id
             LEFT JOIN product_packs pk ON pk.id = hp.pack_id
             LEFT JOIN stock_threshold_preferences sp
               ON sp.home_id = hp.home_id AND sp.home_product_id = hp.id
              AND sp.updated_at <= :as_of
             LEFT JOIN (
                 SELECT spr.home_product_id AS homeProductId,
                        spr.minimum_quantity AS minimumQuantity,
                        spr.always_keep AS alwaysKeep,
                        spr.never_suggest AS neverSuggest,
                        spr.preferred_pack_id AS preferredPackId,
                        spr.lead_time_days AS leadTimeDays,
                        spr.target_coverage_days AS targetCoverageDays,
                        spr.snooze_until AS snoozeUntil
                 FROM stock_preference_revisions spr
                 WHERE spr.home_id = :home
                   AND spr.changed_at <= :as_of
                   AND spr.revision = (
                       SELECT MAX(latest.revision)
                       FROM stock_preference_revisions latest
                       WHERE latest.home_id = spr.home_id
                         AND latest.home_product_id = spr.home_product_id
                         AND latest.changed_at <= :as_of
                   )
             ) pref_history ON pref_history.homeProductId = hp.id
             WHERE hp.home_id = :home AND hp.status = :status
               AND hp.created_at <= :as_of
             ORDER BY hp.id',
            [
                'empty' => '',
                'as_of' => $this->date($asOf),
                'home' => $homeId,
                'status' => 'active',
            ],
        );
        foreach ($rows as &$row) {
            $row['preferredPackId'] = $this->nullableJsonString($row['preferredPackId'] ?? null);
            $row['snoozeUntil'] = $this->nullableJsonString($row['snoozeUntil'] ?? null);
            $row['minimumQuantity'] = $this->nullableJsonString($row['minimumQuantity'] ?? null);
            $row['targetCoverageDays'] = $this->nullableJsonInt($row['targetCoverageDays'] ?? null);
            $row['leadTimeDays'] = $this->nullableJsonInt($row['leadTimeDays'] ?? null) ?? 0;
            $row['alwaysKeep'] = $this->jsonBool($row['alwaysKeep'] ?? false);
            $row['neverSuggest'] = $this->jsonBool($row['neverSuggest'] ?? false);
        }
        unset($row);

        return $rows;
    }

    public function reliableCounts(string $homeId, DateTimeImmutable $asOf): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT scl.home_product_id AS homeProductId, scl.quantity,
                    scs.closed_at AS occurredAt
             FROM stock_count_lines scl
             INNER JOIN stock_count_sessions scs
               ON scs.id = scl.session_id AND scs.home_id = scl.home_id
             WHERE scl.home_id = :home
               AND scl.status = :line_status
               AND scs.status = :session_status
               AND scs.scope_complete = :complete
               AND scs.reliability = :reliability
               AND scs.closed_at <= :as_of
               AND scs.updated_at <= :as_of
               AND scl.updated_at <= :as_of
             ORDER BY scl.home_product_id, scs.closed_at, scl.id',
            [
                'home' => $homeId,
                'line_status' => 'confirmed',
                'session_status' => 'closed',
                'complete' => true,
                'reliability' => 'reliable',
                'as_of' => $this->date($asOf),
            ],
        );
    }

    public function inboundMovements(string $homeId, DateTimeImmutable $asOf): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT home_product_id AS homeProductId, quantity_delta AS quantity,
                    occurred_at AS occurredAt
             FROM stock_movements
             WHERE home_id = :home AND occurred_at <= :as_of
               AND created_at <= :as_of
               AND quantity_delta > 0 AND movement_type <> :count_type
             ORDER BY home_product_id, occurred_at, id',
            [
                'home' => $homeId,
                'as_of' => $this->date($asOf),
                'count_type' => 'count-reconciliation',
            ],
        );
    }

    public function purchaseMovements(string $homeId, DateTimeImmutable $asOf): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT DISTINCT home_product_id AS homeProductId, occurred_at AS occurredAt
             FROM stock_movements
             WHERE home_id = :home AND source_type = :source
               AND quantity_delta > 0 AND occurred_at <= :as_of
               AND created_at <= :as_of
             ORDER BY home_product_id, occurred_at',
            [
                'home' => $homeId,
                'source' => 'receipt-line',
                'as_of' => $this->date($asOf),
            ],
        );
    }

    public function packOptions(string $homeId, DateTimeImmutable $asOf): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT hp.id AS homeProductId, candidate.id AS packId,
                    candidate.normalized_base_amount AS packBase,
                    po.store_id AS storeId, po.currency,
                    po.quantity AS priceQuantity, po.line_total AS lineTotal,
                    po.observed_at AS observedAt
             FROM home_products hp
             INNER JOIN product_packs current_pack ON current_pack.id = hp.pack_id
             INNER JOIN units current_unit ON current_unit.id = current_pack.unit_id
             INNER JOIN product_packs candidate
               ON candidate.product_id = hp.product_id
              AND candidate.status = :pack_status
              AND candidate.normalized_base_amount IS NOT NULL
             INNER JOIN units candidate_unit
               ON candidate_unit.id = candidate.unit_id
              AND candidate_unit.dimension = current_unit.dimension
             INNER JOIN price_observations po
               ON po.home_id = hp.home_id AND po.product_pack_id = candidate.id
             WHERE hp.home_id = :home AND hp.status = :home_status
               AND current_pack.normalized_base_amount IS NOT NULL
               AND po.observed_at <= :as_of
               AND po.created_at <= :as_of
             ORDER BY hp.id, candidate.id, po.currency, po.store_id, po.observed_at DESC, po.id DESC',
            [
                'pack_status' => 'published',
                'home' => $homeId,
                'home_status' => 'active',
                'as_of' => $this->date($asOf),
            ],
        );
    }

    public function inputWatermark(string $homeId, DateTimeImmutable $asOf): string
    {
        $through = $this->date($asOf);
        $values = [
            $this->connection->fetchOne(
                'SELECT MAX(created_at) FROM stock_movements
                 WHERE home_id = :home AND occurred_at <= :through AND created_at <= :through',
                ['home' => $homeId, 'through' => $through],
            ),
            $this->connection->fetchOne(
                'SELECT MAX(updated_at) FROM stock_count_sessions WHERE home_id = :home AND updated_at <= :through',
                ['home' => $homeId, 'through' => $through],
            ),
            $this->connection->fetchOne(
                'SELECT MAX(created_at) FROM price_observations
                 WHERE home_id = :home AND observed_at <= :through AND created_at <= :through',
                ['home' => $homeId, 'through' => $through],
            ),
            $this->connection->fetchOne(
                'SELECT MAX(changed_at) FROM stock_preference_revisions
                 WHERE home_id = :home AND changed_at <= :through',
                ['home' => $homeId, 'through' => $through],
            ),
        ];

        return hash('sha256', $this->json([$homeId, $through, $values]));
    }

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
    ): void {
        $now = $this->date($asOf);
        $this->connection->insert('consumption_estimate_runs', [
            'id' => $estimateRunId,
            'home_id' => $homeId,
            'method_version' => ConsumptionEstimator::VERSION,
            'as_of' => $now,
            'input_watermark' => $watermark,
            'status' => 'completed',
            'created_by_user_id' => $actorUserId,
            'created_at' => $now,
        ]);
        foreach ($estimates as $estimate) {
            $this->connection->insert('consumption_estimates', [
                'id' => $estimate['id'],
                'home_id' => $homeId,
                'run_id' => $estimateRunId,
                'home_product_id' => $estimate['homeProductId'],
                'method' => $estimate['method'],
                'daily_rate' => $estimate['dailyRate'],
                'variability' => $estimate['variability'],
                'sample_intervals' => $estimate['sampleIntervals'],
                'coverage_days' => $estimate['coverageDays'],
                'purchase_samples' => $estimate['purchaseSamples'],
                'purchase_cadence_days' => $estimate['purchaseCadenceDays'],
                'next_expected_shopping_at' => $this->nullableDate(
                    $estimate['nextExpectedShoppingAt'],
                ),
                'confidence_score' => $estimate['confidenceScore'],
                'confidence_band' => $estimate['confidenceBand'],
                'evidence_from' => $this->nullableDate($estimate['evidenceFrom']),
                'evidence_to' => $this->nullableDate($estimate['evidenceTo']),
                'limitations_json' => $this->json($estimate['limitations']),
                'created_at' => $now,
            ]);
        }
        $this->connection->insert('shopping_suggestion_runs', [
            'id' => $suggestionRunId,
            'home_id' => $homeId,
            'estimate_run_id' => $estimateRunId,
            'model_version' => SuggestionEngine::VERSION,
            'as_of' => $now,
            'horizon_days' => $horizonDays,
            'input_watermark' => $watermark,
            'status' => 'completed',
            'created_by_user_id' => $actorUserId,
            'created_at' => $now,
        ]);
        foreach ($suggestions as $suggestion) {
            $this->connection->insert('shopping_suggestions', [
                'id' => $suggestion['id'],
                'home_id' => $homeId,
                'run_id' => $suggestionRunId,
                'home_product_id' => $suggestion['homeProductId'],
                'expected_demand' => $suggestion['expectedDemand'],
                'safety_stock' => $suggestion['safetyStock'],
                'factual_stock' => $suggestion['factualStock'],
                'usable_stock' => $suggestion['usableStock'],
                'required_quantity' => $suggestion['requiredQuantity'],
                'selected_pack_id' => $suggestion['selectedPackId'],
                'pack_count' => $suggestion['packCount'],
                'confidence_score' => $suggestion['confidenceScore'],
                'confidence_band' => $suggestion['confidenceBand'],
                'status' => 'active',
                'expires_at' => $this->date($asOf->modify('+1 day')),
                'created_at' => $now,
            ]);
            $this->connection->insert('suggestion_explanations', [
                'suggestion_id' => $suggestion['id'],
                'factors_json' => $this->json($suggestion['factors']),
                'limitations_json' => $this->json($suggestion['limitations']),
                'created_at' => $now,
            ]);
            foreach ($suggestion['packOptions'] as $option) {
                $this->connection->insert('suggestion_pack_options', [
                    'id' => $option['id'],
                    'home_id' => $homeId,
                    'suggestion_id' => $suggestion['id'],
                    'pack_id' => $option['packId'],
                    'store_id' => $option['storeId'],
                    'currency' => $option['currency'],
                    'pack_count' => $option['packCount'],
                    'effective_total' => $option['effectiveTotal'],
                    'excess_quantity' => $option['excessQuantity'],
                    'price_observed_at' => $this->date($option['priceObservedAt']),
                    'selected' => $option['selected'],
                    'reason' => $option['reason'],
                    'created_at' => $now,
                ]);
            }
        }
        $this->audit(
            $auditEventId,
            $homeId,
            $actorUserId,
            'shopping.suggestion-run.completed',
            'shopping_suggestion_run',
            $suggestionRunId,
            [
                'estimateRunId' => $estimateRunId,
                'estimateCount' => count($estimates),
                'suggestionCount' => count($suggestions),
                'modelVersion' => SuggestionEngine::VERSION,
            ],
            $asOf,
        );
    }

    public function latestEstimates(string $homeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ce.id, ce.home_product_id AS homeProductId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    ce.method, ce.daily_rate AS dailyRate,
                    ce.variability, ce.sample_intervals AS sampleIntervals,
                    ce.coverage_days AS coverageDays,
                    ce.purchase_samples AS purchaseSamples,
                    ce.purchase_cadence_days AS purchaseCadenceDays,
                    ce.next_expected_shopping_at AS nextExpectedShoppingAt,
                    ce.confidence_score AS confidenceScore,
                    ce.confidence_band AS confidenceBand,
                    ce.evidence_from AS evidenceFrom, ce.evidence_to AS evidenceTo,
                    ce.limitations_json AS limitationsJson,
                    cer.as_of AS asOf, cer.input_watermark AS inputWatermark
             FROM consumption_estimates ce
             INNER JOIN consumption_estimate_runs cer ON cer.id = ce.run_id
             INNER JOIN home_products hp
               ON hp.id = ce.home_product_id AND hp.home_id = ce.home_id
             LEFT JOIN products p ON p.id = hp.product_id
             WHERE ce.home_id = :home
               AND ce.run_id = (
                   SELECT id FROM consumption_estimate_runs
                   WHERE home_id = :home AND status = :status
                   ORDER BY as_of DESC, id DESC LIMIT 1
               )
             ORDER BY productName, ce.id',
            ['home' => $homeId, 'status' => 'completed'],
        );
        foreach ($rows as &$row) {
            $row['limitations'] = $this->decodeList((string) $row['limitationsJson']);
            unset($row['limitationsJson']);
        }
        unset($row);

        return $rows;
    }

    public function latestSuggestions(string $homeId, DateTimeImmutable $asOf): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT ss.id, ss.home_product_id AS homeProductId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    COALESCE(pk.original_pack_text, hp.original_pack_text, :empty) AS packText,
                    ss.expected_demand AS expectedDemand, ss.safety_stock AS safetyStock,
                    ss.factual_stock AS factualStock, ss.usable_stock AS usableStock,
                    ss.required_quantity AS requiredQuantity,
                    ss.selected_pack_id AS selectedPackId, ss.pack_count AS packCount,
                    ss.confidence_score AS confidenceScore,
                    ss.confidence_band AS confidenceBand, ss.status,
                    ss.expires_at AS expiresAt, ssr.as_of AS asOf,
                    ssr.model_version AS modelVersion,
                    ssr.input_watermark AS inputWatermark
             FROM shopping_suggestions ss
             INNER JOIN shopping_suggestion_runs ssr ON ssr.id = ss.run_id
             INNER JOIN home_products hp
               ON hp.id = ss.home_product_id AND hp.home_id = ss.home_id
             LEFT JOIN products p ON p.id = hp.product_id
             LEFT JOIN product_packs pk ON pk.id = hp.pack_id
             WHERE ss.home_id = :home AND ss.status = :active
               AND ss.expires_at >= :as_of
               AND ss.run_id = (
                   SELECT id FROM shopping_suggestion_runs
                   WHERE home_id = :home AND status = :completed
                   ORDER BY as_of DESC, id DESC LIMIT 1
               )
             ORDER BY productName, ss.id',
            [
                'empty' => '',
                'home' => $homeId,
                'active' => 'active',
                'as_of' => $this->date($asOf),
                'completed' => 'completed',
            ],
        );
    }

    public function latestPriceComparisons(string $homeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT spo.suggestion_id AS suggestionId,
                    ss.home_product_id AS homeProductId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    spo.pack_id AS packId, pk.original_pack_text AS packText,
                    spo.store_id AS storeId, st.name AS storeName,
                    spo.currency, spo.pack_count AS packCount,
                    spo.effective_total AS effectiveTotal,
                    spo.excess_quantity AS excessQuantity,
                    spo.price_observed_at AS priceObservedAt,
                    spo.selected, spo.reason
             FROM suggestion_pack_options spo
             INNER JOIN shopping_suggestions ss ON ss.id = spo.suggestion_id
             INNER JOIN home_products hp
               ON hp.id = ss.home_product_id AND hp.home_id = ss.home_id
             LEFT JOIN products p ON p.id = hp.product_id
             INNER JOIN product_packs pk ON pk.id = spo.pack_id
             LEFT JOIN stores st ON st.id = spo.store_id AND st.home_id = spo.home_id
             WHERE spo.home_id = :home
               AND ss.run_id = (
                   SELECT id FROM shopping_suggestion_runs
                   WHERE home_id = :home AND status = :status
                   ORDER BY as_of DESC, id DESC LIMIT 1
               )
             ORDER BY productName, spo.currency, spo.effective_total, spo.pack_id',
            ['home' => $homeId, 'status' => 'completed'],
        );
    }

    public function explanation(string $homeId, string $suggestionId): ?array
    {
        $row = $this->one(
            'SELECT ss.id, ss.home_product_id AS homeProductId,
                    ss.required_quantity AS requiredQuantity,
                    ss.confidence_score AS confidenceScore,
                    ss.confidence_band AS confidenceBand,
                    se.factors_json AS factorsJson,
                    se.limitations_json AS limitationsJson,
                    ssr.model_version AS modelVersion,
                    ssr.as_of AS asOf, ssr.input_watermark AS inputWatermark
             FROM shopping_suggestions ss
             INNER JOIN suggestion_explanations se ON se.suggestion_id = ss.id
             INNER JOIN shopping_suggestion_runs ssr ON ssr.id = ss.run_id
             WHERE ss.home_id = :home AND ss.id = :id',
            ['home' => $homeId, 'id' => $suggestionId],
        );
        if ($row === null) {
            return null;
        }
        $row['factors'] = $this->decodeListOfMaps((string) $row['factorsJson']);
        $row['limitations'] = $this->decodeList((string) $row['limitationsJson']);
        $row['packOptions'] = $this->connection->fetchAllAssociative(
            'SELECT pack_id AS packId, store_id AS storeId, currency, pack_count AS packCount,
                    effective_total AS effectiveTotal, excess_quantity AS excessQuantity,
                    price_observed_at AS priceObservedAt, selected, reason
             FROM suggestion_pack_options
             WHERE home_id = :home AND suggestion_id = :suggestion
             ORDER BY currency, effective_total, pack_id',
            ['home' => $homeId, 'suggestion' => $suggestionId],
        );
        unset($row['factorsJson'], $row['limitationsJson']);

        return $row;
    }

    public function preference(string $homeId, string $homeProductId): ?array
    {
        return $this->one(
            'SELECT home_product_id AS homeProductId, minimum_quantity AS minimumQuantity,
                    always_keep AS alwaysKeep, never_suggest AS neverSuggest,
                    preferred_pack_id AS preferredPackId, lead_time_days AS leadTimeDays,
                    target_coverage_days AS targetCoverageDays,
                    snooze_until AS snoozeUntil, revision, updated_at AS updatedAt
             FROM stock_threshold_preferences
             WHERE home_id = :home AND home_product_id = :product',
            ['home' => $homeId, 'product' => $homeProductId],
        );
    }

    public function savePreference(
        string $homeId,
        string $homeProductId,
        string $actorUserId,
        string $auditEventId,
        string $revisionRecordId,
        array $preference,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool {
        $product = $this->one(
            'SELECT product_id AS productId FROM home_products
             WHERE id = :id AND home_id = :home AND status = :status',
            ['id' => $homeProductId, 'home' => $homeId, 'status' => 'active'],
        );
        if ($product === null) {
            throw new DomainException('The home product is unavailable.');
        }
        if ($preference['preferredPackId'] !== null) {
            $pack = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM product_packs
                 WHERE id = :pack AND product_id = :product AND status <> :archived',
                [
                    'pack' => $preference['preferredPackId'],
                    'product' => $product['productId'],
                    'archived' => 'archived',
                ],
            );
            if ((int) $pack !== 1) {
                throw new DomainException('The preferred pack is unavailable for this product.');
            }
        }
        $existing = $this->one(
            'SELECT revision FROM stock_threshold_preferences
             WHERE home_id = :home AND home_product_id = :product',
            ['home' => $homeId, 'product' => $homeProductId],
        );
        $now = $this->date($at);
        if ($existing === null) {
            if ($expectedRevision !== 0) {
                return false;
            }
            $this->connection->insert('stock_threshold_preferences', [
                'home_id' => $homeId,
                'home_product_id' => $homeProductId,
                'minimum_quantity' => $preference['minimumQuantity'],
                'always_keep' => $preference['alwaysKeep'],
                'never_suggest' => $preference['neverSuggest'],
                'preferred_pack_id' => $preference['preferredPackId'],
                'lead_time_days' => $preference['leadTimeDays'],
                'target_coverage_days' => $preference['targetCoverageDays'],
                'snooze_until' => $preference['snoozeUntil'],
                'revision' => 1,
                'updated_at' => $now,
            ]);
            $revision = 1;
        } else {
            if ((int) $existing['revision'] !== $expectedRevision) {
                return false;
            }
            $updated = $this->connection->executeStatement(
                'UPDATE stock_threshold_preferences
                 SET minimum_quantity = :minimum, always_keep = :always_keep,
                     never_suggest = :never_suggest, preferred_pack_id = :preferred_pack,
                     lead_time_days = :lead_time, target_coverage_days = :coverage,
                     snooze_until = :snooze, revision = revision + 1, updated_at = :updated
                 WHERE home_id = :home AND home_product_id = :product
                   AND revision = :revision',
                [
                    'minimum' => $preference['minimumQuantity'],
                    'always_keep' => $preference['alwaysKeep'],
                    'never_suggest' => $preference['neverSuggest'],
                    'preferred_pack' => $preference['preferredPackId'],
                    'lead_time' => $preference['leadTimeDays'],
                    'coverage' => $preference['targetCoverageDays'],
                    'snooze' => $preference['snoozeUntil'],
                    'updated' => $now,
                    'home' => $homeId,
                    'product' => $homeProductId,
                    'revision' => $expectedRevision,
                ],
            );
            if ($updated !== 1) {
                return false;
            }
            $revision = $expectedRevision + 1;
        }
        $this->connection->insert('stock_preference_revisions', [
            'id' => $revisionRecordId,
            'home_id' => $homeId,
            'home_product_id' => $homeProductId,
            'revision' => $revision,
            'minimum_quantity' => $preference['minimumQuantity'],
            'always_keep' => $preference['alwaysKeep'],
            'never_suggest' => $preference['neverSuggest'],
            'preferred_pack_id' => $preference['preferredPackId'],
            'lead_time_days' => $preference['leadTimeDays'],
            'target_coverage_days' => $preference['targetCoverageDays'],
            'snooze_until' => $preference['snoozeUntil'],
            'preference_json' => $this->json($preference),
            'changed_at' => $now,
        ]);
        $this->audit(
            $auditEventId,
            $homeId,
            $actorUserId,
            'shopping.preference.changed',
            'stock_threshold_preference',
            $homeProductId,
            ['revision' => $revision],
            $at,
        );

        return true;
    }

    public function suggestion(string $homeId, string $suggestionId): ?array
    {
        return $this->one(
            'SELECT id, required_quantity AS requiredQuantity, status
             FROM shopping_suggestions WHERE id = :id AND home_id = :home',
            ['id' => $suggestionId, 'home' => $homeId],
        );
    }

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
    ): void {
        $now = $this->date($at);
        $this->connection->insert('user_suggestion_feedback', [
            'id' => $id,
            'home_id' => $homeId,
            'suggestion_id' => $suggestionId,
            'actor_user_id' => $actorUserId,
            'decision' => $decision,
            'original_quantity' => $originalQuantity,
            'result_quantity' => $resultQuantity,
            'reason' => $reason,
            'created_at' => $now,
        ]);
        $status = match ($decision) {
            'accepted', 'edited' => 'accepted',
            'dismissed' => 'dismissed',
            'snoozed' => 'snoozed',
            default => throw new \LogicException('Unsupported suggestion feedback decision.'),
        };
        $this->connection->update(
            'shopping_suggestions',
            ['status' => $status],
            ['id' => $suggestionId, 'home_id' => $homeId],
        );
        $this->audit(
            $auditEventId,
            $homeId,
            $actorUserId,
            'shopping.suggestion-feedback.recorded',
            'shopping_suggestion',
            $suggestionId,
            ['decision' => $decision],
            $at,
        );
    }

    public function purchasesBetween(
        string $homeId,
        DateTimeImmutable $after,
        DateTimeImmutable $through,
    ): array {
        return $this->connection->fetchAllAssociative(
            'SELECT DISTINCT home_product_id AS homeProductId
             FROM stock_movements
             WHERE home_id = :home AND source_type = :source
               AND quantity_delta > 0
               AND occurred_at > :after AND occurred_at <= :through
             ORDER BY home_product_id',
            [
                'home' => $homeId,
                'source' => 'receipt-line',
                'after' => $this->date($after),
                'through' => $this->date($through),
            ],
        );
    }

    public function feedbackSummary(string $homeId, DateTimeImmutable $asOf): array
    {
        $row = $this->one(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN decision IN (:edited, :dismissed, :snoozed)
                             THEN 1 ELSE 0 END) AS overrides
             FROM user_suggestion_feedback
             WHERE home_id = :home AND created_at <= :as_of',
            [
                'edited' => 'edited',
                'dismissed' => 'dismissed',
                'snoozed' => 'snoozed',
                'home' => $homeId,
                'as_of' => $this->date($asOf),
            ],
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'overrides' => (int) ($row['overrides'] ?? 0),
        ];
    }

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
    ): void {
        $now = $this->date($at);
        $this->connection->insert('suggestion_backtest_runs', [
            'id' => $id,
            'home_id' => $homeId,
            'model_version' => SuggestionEngine::VERSION,
            'cutoffs_json' => $this->json($cutoffs),
            'evaluation_days' => $evaluationDays,
            'status' => 'completed',
            'requested_by_user_id' => $actorUserId,
            'metrics_json' => $this->json($metrics),
            'limitations_json' => $this->json($limitations),
            'created_at' => $now,
            'completed_at' => $now,
        ]);
        foreach ($results as $result) {
            $this->connection->insert('suggestion_backtest_results', [
                'id' => $result['id'],
                'home_id' => $homeId,
                'run_id' => $id,
                'cutoff_at' => $this->date($result['cutoffAt']),
                'home_product_id' => $result['homeProductId'],
                'suggested' => $result['suggested'],
                'purchased_later' => $result['purchasedLater'],
                'suggested_quantity' => $result['suggestedQuantity'],
                'confidence_band' => $result['confidenceBand'],
                'created_at' => $now,
            ]);
        }
        $this->audit(
            $auditEventId,
            $homeId,
            $actorUserId,
            'shopping.backtest.completed',
            'suggestion_backtest_run',
            $id,
            [
                'cutoffCount' => count($cutoffs),
                'evaluatedRows' => count($results),
                'modelVersion' => SuggestionEngine::VERSION,
            ],
            $at,
        );
    }

    public function backtest(string $homeId, string $runId): ?array
    {
        $row = $this->one(
            'SELECT id, model_version AS modelVersion, cutoffs_json AS cutoffsJson,
                    evaluation_days AS evaluationDays, status,
                    metrics_json AS metricsJson, limitations_json AS limitationsJson,
                    created_at AS createdAt, completed_at AS completedAt
             FROM suggestion_backtest_runs WHERE id = :id AND home_id = :home',
            ['id' => $runId, 'home' => $homeId],
        );
        if ($row === null) {
            return null;
        }
        $row['cutoffs'] = $this->decodeList((string) $row['cutoffsJson']);
        $row['metrics'] = $this->decodeMap((string) $row['metricsJson']);
        $row['limitations'] = $this->decodeList((string) $row['limitationsJson']);
        $row['resultSummary'] = $this->connection->fetchAllAssociative(
            'SELECT cutoff_at AS cutoffAt,
                    COUNT(*) AS evaluatedRows,
                    SUM(CASE WHEN suggested = 1 AND purchased_later = 1 THEN 1 ELSE 0 END) AS truePositive,
                    SUM(CASE WHEN suggested = 1 AND purchased_later = 0 THEN 1 ELSE 0 END) AS falsePositive,
                    SUM(CASE WHEN suggested = 0 AND purchased_later = 1 THEN 1 ELSE 0 END) AS falseNegative
             FROM suggestion_backtest_results
             WHERE home_id = :home AND run_id = :run
             GROUP BY cutoff_at ORDER BY cutoff_at',
            ['home' => $homeId, 'run' => $runId],
        );
        unset($row['cutoffsJson'], $row['metricsJson'], $row['limitationsJson']);

        return $row;
    }

    /**
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $parameters): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $parameters);

        return $row === false ? null : $row;
    }

    private function nullableJsonString(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return (string) $value;
    }

    private function nullableJsonInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return (int) $value;
    }

    private function jsonBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    private function nullableDate(mixed $value): ?string
    {
        return $value instanceof DateTimeImmutable ? $this->date($value) : null;
    }

    /**
     * @return list<string> */
    private function decodeList(string $value): array
    {
        $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * @return list<array<string, mixed>> */
    private function decodeListOfMaps(string $value): array
    {
        $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @return array<string, mixed> */
    private function decodeMap(string $value): array
    {
        $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $details */
    private function audit(
        string $id,
        string $homeId,
        string $actorUserId,
        string $action,
        string $targetType,
        string $targetId,
        array $details,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('audit_events', [
            'id' => $id,
            'home_id' => $homeId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $this->json($details),
            'occurred_at' => $this->date($at),
        ]);
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
