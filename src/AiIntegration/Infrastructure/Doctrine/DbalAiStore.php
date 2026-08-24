<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Providentia\AiIntegration\Application\AiMaturityStore;
use Providentia\AiIntegration\Application\AiStore;
use Providentia\AiIntegration\Application\Media\EncryptedMediaObject;

final class DbalAiStore implements AiStore, AiMaturityStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function settings(string $homeId): ?array
    {
        return $this->one(
            'SELECT home_id AS homeId, mode, provider, model, revision,
                    updated_by_user_id AS updatedByUserId,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM ai_settings WHERE home_id = :home',
            ['home' => $homeId],
        );
    }

    public function saveSettings(
        string $homeId,
        string $mode,
        ?string $provider,
        ?string $model,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        $now = $this->date($at);
        if ($expectedRevision === 0) {
            if ($this->settings($homeId) !== null) {
                return false;
            }
            try {
                $this->connection->insert('ai_settings', [
                    'home_id' => $homeId,
                    'mode' => $mode,
                    'provider' => $provider,
                    'model' => $model,
                    'revision' => 1,
                    'updated_by_user_id' => $actorUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            return true;
        }

        return $this->connection->executeStatement(
            'UPDATE ai_settings
             SET mode = :mode, provider = :provider, model = :model,
                 revision = revision + 1, updated_by_user_id = :actor,
                 updated_at = :updated
             WHERE home_id = :home AND revision = :revision',
            [
                'mode' => $mode,
                'provider' => $provider,
                'model' => $model,
                'actor' => $actorUserId,
                'updated' => $now,
                'home' => $homeId,
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function credential(string $homeId, string $provider): ?array
    {
        return $this->one(
            'SELECT id, provider, ciphertext, nonce, key_version AS keyVersion,
                    last_four AS lastFour, status, created_at AS createdAt,
                    updated_at AS updatedAt
             FROM ai_provider_credentials
             WHERE home_id = :home AND provider = :provider AND status = :status',
            ['home' => $homeId, 'provider' => $provider, 'status' => 'active'],
        );
    }

    public function saveCredential(
        string $id,
        string $homeId,
        string $provider,
        string $ciphertext,
        string $nonce,
        int $keyVersion,
        string $lastFour,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void {
        $existing = $this->one(
            'SELECT id FROM ai_provider_credentials
             WHERE home_id = :home AND provider = :provider',
            ['home' => $homeId, 'provider' => $provider],
        );
        $values = [
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
            'key_version' => $keyVersion,
            'last_four' => $lastFour,
            'status' => 'active',
            'rotated_by_user_id' => $actorUserId,
            'updated_at' => $this->date($at),
        ];
        if ($existing === null) {
            try {
                $this->connection->insert('ai_provider_credentials', array_merge($values, [
                    'id' => $id,
                    'home_id' => $homeId,
                    'provider' => $provider,
                    'created_at' => $this->date($at),
                ]));

                return;
            } catch (UniqueConstraintViolationException) {
                $existing = $this->one(
                    'SELECT id FROM ai_provider_credentials
                     WHERE home_id = :home AND provider = :provider',
                    ['home' => $homeId, 'provider' => $provider],
                );
            }
        }
        if ($existing === null) {
            throw new \RuntimeException('Concurrent AI credential write could not be recovered.');
        }
        $this->connection->update(
            'ai_provider_credentials',
            $values,
            ['id' => $existing['id'], 'home_id' => $homeId],
        );
    }

    public function removeCredential(string $homeId, string $provider, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ai_provider_credentials
             SET ciphertext = :empty, nonce = :empty, status = :status, updated_at = :updated
             WHERE home_id = :home AND provider = :provider AND status = :active',
            [
                'empty' => '',
                'status' => 'revoked',
                'updated' => $this->date($at),
                'home' => $homeId,
                'provider' => $provider,
                'active' => 'active',
            ],
        ) === 1;
    }

    public function targetExists(string $homeId, string $kind, ?string $targetId): bool
    {
        if ($kind === 'receipt') {
            // Receipt intake starts with untrusted images; the ordinary draft
            // receipt is deliberately created only after human review. When a
            // draft is supplied, it must still belong to this home.
            if ($targetId === null || $targetId === '') {
                return true;
            }
            $table = 'receipts';
            $status = 'draft';
        } elseif ($kind === 'stock') {
            if ($targetId === null || $targetId === '') {
                return false;
            }
            $table = 'stock_count_sessions';
            $status = 'open';
        } else {
            return false;
        }
        $sql = 'SELECT COUNT(*) FROM ' . $table
            . ' WHERE id = :id AND home_id = :home AND status = :status';

        return (int) $this->connection->fetchOne($sql, [
            'id' => $targetId,
            'home' => $homeId,
            'status' => $status,
        ]) === 1;
    }

    public function startExtraction(
        string $id,
        string $homeId,
        string $kind,
        ?string $targetId,
        string $provider,
        string $model,
        string $mimeType,
        string $digest,
        int $byteCount,
        int $promptTemplateVersion,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $this->connection->insert('ai_extractions', [
            'id' => $id,
            'home_id' => $homeId,
            'kind' => $kind,
            'target_id' => $targetId,
            'provider' => $provider,
            'model' => $model,
            'status' => 'processing',
            'input_mime_type' => $mimeType,
            'input_sha256' => $digest,
            'input_byte_count' => $byteCount,
            'schema_version' => 1,
            'prompt_template_version' => $promptTemplateVersion,
            'processing_ms' => null,
            'usage_json' => null,
            'result_json' => null,
            'error_code' => null,
            'error_detail' => null,
            'created_by_user_id' => $actorUserId,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function completeExtraction(
        string $id,
        string $homeId,
        array $result,
        array $usage,
        int $processingMs,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        /** @var list<array<string, mixed>> $candidates */
        $candidates = $result['candidates'];
        foreach ($candidates as $position => $candidate) {
            $this->connection->insert('ai_extraction_candidates', [
                'home_id' => $homeId,
                'extraction_id' => $id,
                'position' => $position,
                'candidate_type' => $candidate['candidateType'],
                'payload_json' => json_encode(
                    $candidate,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
                'confidence' => number_format((float) $candidate['confidence'], 4, '.', ''),
                'review_status' => 'pending',
                'revision' => 1,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $updated = $this->connection->update('ai_extractions', [
            'status' => 'review_required',
            'result_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'usage_json' => json_encode($usage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'processing_ms' => $processingMs,
            'completed_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id, 'home_id' => $homeId, 'status' => 'processing']);
        if ($updated !== 1) {
            throw new \RuntimeException('AI extraction changed while candidates were being completed.');
        }
    }

    public function failExtraction(
        string $id,
        string $homeId,
        string $code,
        string $safeDetail,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $this->connection->update('ai_extractions', [
            'status' => 'failed',
            'error_code' => $code,
            'error_detail' => mb_substr($safeDetail, 0, 500),
            'completed_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id, 'home_id' => $homeId, 'status' => 'processing']);
    }

    public function extraction(string $homeId, string $id): ?array
    {
        $extraction = $this->one(
            'SELECT id, kind, target_id AS targetId, provider, model, status,
                    input_mime_type AS inputMimeType, input_sha256 AS inputSha256,
                    input_byte_count AS inputByteCount, schema_version AS schemaVersion,
                    prompt_template_version AS promptTemplateVersion,
                    processing_ms AS processingMs, usage_json AS usageJson,
                    result_json AS resultJson, error_code AS errorCode,
                    error_detail AS errorDetail, created_by_user_id AS createdByUserId,
                    completed_at AS completedAt, created_at AS createdAt,
                    updated_at AS updatedAt
             FROM ai_extractions WHERE home_id = :home AND id = :id',
            ['home' => $homeId, 'id' => $id],
        );
        if ($extraction === null) {
            return null;
        }
        $extraction['result'] = $extraction['resultJson'] === null
            ? null
            : json_decode((string) $extraction['resultJson'], true, 128, JSON_THROW_ON_ERROR);
        unset($extraction['resultJson']);
        $extraction['usage'] = $extraction['usageJson'] === null
            ? null
            : json_decode((string) $extraction['usageJson'], true, 16, JSON_THROW_ON_ERROR);
        unset($extraction['usageJson']);
        $extraction['candidates'] = $this->connection->fetchAllAssociative(
            'SELECT position, candidate_type AS candidateType,
                    payload_json AS payloadJson, confidence,
                    review_status AS reviewStatus, revision,
                    reviewed_by_user_id AS reviewedByUserId,
                    reviewed_at AS reviewedAt
             FROM ai_extraction_candidates
             WHERE home_id = :home AND extraction_id = :extraction
             ORDER BY position',
            ['home' => $homeId, 'extraction' => $id],
        );
        foreach ($extraction['candidates'] as &$candidate) {
            $candidate['payload'] = json_decode(
                (string) $candidate['payloadJson'],
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
            unset($candidate['payloadJson']);
        }
        unset($candidate);
        $extraction['attempts'] = [];
        $extraction['discrepancies'] = [];
        $extraction['observationDecisions'] = [];
        if ($this->tableExists('ai_extraction_attempts')) {
            $extraction['attempts'] = $this->connection->fetchAllAssociative(
                'SELECT position, purpose, observation_index AS observationIndex,
                        profile_id AS profileId, provider, model, status,
                        error_code AS errorCode, estimated_cost_micros AS estimatedCostMicros,
                        created_at AS createdAt
                 FROM ai_extraction_attempts WHERE extraction_id = :extraction ORDER BY position',
                ['extraction' => $id],
            );
            $extraction['discrepancies'] = $this->connection->fetchAllAssociative(
                'SELECT position, observation_index AS observationIndex, payload_json AS payloadJson,
                        review_status AS reviewStatus, revision,
                        reviewed_by_user_id AS reviewedByUserId, reviewed_at AS reviewedAt,
                        created_at AS createdAt
                 FROM ai_extraction_discrepancies WHERE extraction_id = :extraction ORDER BY position',
                ['extraction' => $id],
            );
            foreach ($extraction['discrepancies'] as &$discrepancy) {
                $discrepancy['payload'] = json_decode(
                    (string) $discrepancy['payloadJson'],
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
                unset($discrepancy['payloadJson']);
            }
            unset($discrepancy);
            $extraction['observationDecisions'] = $this->observationDecisions($homeId, $id);
        }

        return $extraction;
    }

    public function reviewCandidate(
        string $homeId,
        string $extractionId,
        int $position,
        string $decision,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE ai_extraction_candidates
             SET review_status = :decision, reviewed_by_user_id = :actor,
                 reviewed_at = :reviewed, revision = revision + 1,
                 updated_at = :updated
             WHERE home_id = :home AND extraction_id = :extraction
               AND position = :position AND revision = :revision',
            [
                'decision' => $decision,
                'actor' => $actorUserId,
                'reviewed' => $this->date($at),
                'updated' => $this->date($at),
                'home' => $homeId,
                'extraction' => $extractionId,
                'position' => $position,
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function providerProfiles(string $homeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, label, provider, model, ciphertext, nonce, key_version AS keyVersion,
                    last_four AS lastFour, estimated_cost_micros AS estimatedCostMicros,
                    status, revision, created_at AS createdAt, updated_at AS updatedAt
             FROM ai_provider_profiles WHERE home_id = :home AND status = :status
             ORDER BY label, id',
            ['home' => $homeId, 'status' => 'active'],
        );
    }

    public function providerProfile(string $homeId, string $profileId): ?array
    {
        return $this->one(
            'SELECT id, label, provider, model, ciphertext, nonce, key_version AS keyVersion,
                    last_four AS lastFour, estimated_cost_micros AS estimatedCostMicros,
                    status, revision, created_at AS createdAt, updated_at AS updatedAt
             FROM ai_provider_profiles WHERE home_id = :home AND id = :id',
            ['home' => $homeId, 'id' => $profileId],
        );
    }

    public function saveProviderProfile(array $profile, int $expectedRevision, DateTimeImmutable $at): bool
    {
        $now = $this->date($at);
        $values = [
            'label' => $profile['label'],
            'provider' => $profile['provider'],
            'model' => $profile['model'],
            'ciphertext' => $profile['ciphertext'],
            'nonce' => $profile['nonce'],
            'key_version' => $profile['keyVersion'],
            'last_four' => $profile['lastFour'],
            'estimated_cost_micros' => $profile['estimatedCostMicros'],
            'status' => 'active',
            'updated_by_user_id' => $profile['actorUserId'],
            'updated_at' => $now,
        ];
        if ($expectedRevision === 0) {
            try {
                $this->connection->insert('ai_provider_profiles', array_merge($values, [
                    'id' => $profile['id'],
                    'home_id' => $profile['homeId'],
                    'revision' => 1,
                    'created_at' => $now,
                ]));

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        $values['revision'] = $expectedRevision + 1;

        try {
            return $this->connection->update(
                'ai_provider_profiles',
                $values,
                [
                    'id' => $profile['id'],
                    'home_id' => $profile['homeId'],
                    'revision' => $expectedRevision,
                    'status' => 'active',
                ],
            ) === 1;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function revokeProviderProfile(
        string $homeId,
        string $profileId,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE ai_provider_profiles SET ciphertext = NULL, nonce = NULL, key_version = NULL,
                    last_four = NULL, status = :revoked, revision = revision + 1,
                    updated_by_user_id = :actor, updated_at = :updated
             WHERE home_id = :home AND id = :id AND revision = :revision AND status = :active',
            [
                'revoked' => 'revoked',
                'actor' => $actorUserId,
                'updated' => $this->date($at),
                'home' => $homeId,
                'id' => $profileId,
                'revision' => $expectedRevision,
                'active' => 'active',
            ],
        ) === 1;
    }

    public function revokeProviderProfileCredential(
        string $auditId,
        string $homeId,
        string $profileId,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        $date = $this->date($at);
        $updated = $this->connection->executeStatement(
            'UPDATE ai_provider_profiles
             SET ciphertext = NULL, nonce = NULL, key_version = NULL, last_four = NULL,
                 revision = revision + 1, updated_by_user_id = :actor, updated_at = :updated
             WHERE home_id = :home AND id = :id AND revision = :revision AND status = :active',
            [
                'actor' => $actorUserId,
                'updated' => $date,
                'home' => $homeId,
                'id' => $profileId,
                'revision' => $expectedRevision,
                'active' => 'active',
            ],
        );
        if ($updated !== 1) {
            return false;
        }
        $this->connection->insert('audit_events', [
            'id' => $auditId,
            'home_id' => $homeId,
            'actor_user_id' => $actorUserId,
            'action' => 'ai.provider-profile.credential-revoked',
            'target_type' => 'ai_provider_profile',
            'target_id' => $profileId,
            'details' => json_encode([
                'expectedRevision' => $expectedRevision,
                'revision' => $expectedRevision + 1,
                'credentialConfigured' => false,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $date,
        ]);

        return true;
    }

    public function orchestrationPolicy(string $homeId): ?array
    {
        $policy = $this->one(
            'SELECT home_id AS homeId, extraction_profile_ids_json AS extractionProfileIdsJson,
                    validation_profile_id AS validationProfileId, max_attempts AS maxAttempts,
                    max_total_tokens AS maxTotalTokens,
                    max_estimated_cost_micros AS maxEstimatedCostMicros,
                    revision, updated_at AS updatedAt
             FROM ai_orchestration_policies WHERE home_id = :home',
            ['home' => $homeId],
        );
        if ($policy === null) {
            return null;
        }
        $policy['extractionProfileIds'] = json_decode(
            (string) $policy['extractionProfileIdsJson'],
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        unset($policy['extractionProfileIdsJson']);

        return $policy;
    }

    public function saveOrchestrationPolicy(
        string $homeId,
        array $extractionProfileIds,
        ?string $validationProfileId,
        int $maxAttempts,
        int $maxTotalTokens,
        int $maxEstimatedCostMicros,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        $now = $this->date($at);
        $values = [
            'extraction_profile_ids_json' => json_encode($extractionProfileIds, JSON_THROW_ON_ERROR),
            'validation_profile_id' => $validationProfileId,
            'max_attempts' => $maxAttempts,
            'max_total_tokens' => $maxTotalTokens,
            'max_estimated_cost_micros' => $maxEstimatedCostMicros,
            'updated_by_user_id' => $actorUserId,
            'updated_at' => $now,
        ];
        if ($expectedRevision === 0) {
            try {
                $this->connection->insert('ai_orchestration_policies', array_merge($values, [
                    'home_id' => $homeId,
                    'revision' => 1,
                    'created_at' => $now,
                ]));

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }
        $values['revision'] = $expectedRevision + 1;

        return $this->connection->update(
            'ai_orchestration_policies',
            $values,
            ['home_id' => $homeId, 'revision' => $expectedRevision],
        ) === 1;
    }

    public function appendExtractionAttempt(
        string $extractionId,
        int $position,
        int $observationIndex,
        array $attempt,
        DateTimeImmutable $at,
    ): void {
        $this->connection->insert('ai_extraction_attempts', [
            'extraction_id' => $extractionId,
            'position' => $position,
            'purpose' => $attempt['purpose'],
            'observation_index' => $observationIndex,
            'profile_id' => $attempt['profileId'] === '' ? null : $attempt['profileId'],
            'provider' => $attempt['provider'],
            'model' => $attempt['model'],
            'status' => $attempt['status'],
            'error_code' => $attempt['errorCode'],
            'estimated_cost_micros' => $attempt['estimatedCostMicros'],
            'created_at' => $this->date($at),
        ]);
    }

    public function appendExtractionDiscrepancies(
        string $extractionId,
        int $observationIndex,
        int $positionOffset,
        array $discrepancies,
        DateTimeImmutable $at,
    ): void {
        foreach ($discrepancies as $offset => $discrepancy) {
            $this->connection->insert('ai_extraction_discrepancies', [
                'extraction_id' => $extractionId,
                'position' => $positionOffset + $offset,
                'observation_index' => $observationIndex,
                'payload_json' => json_encode($discrepancy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'review_status' => 'pending',
                'revision' => 1,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'created_at' => $this->date($at),
            ]);
        }
    }

    public function reviewExtractionDiscrepancy(
        string $homeId,
        string $extractionId,
        int $position,
        string $decision,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE ai_extraction_discrepancies SET review_status = :decision,
                    revision = revision + 1, reviewed_by_user_id = :actor,
                    reviewed_at = :reviewed
             WHERE extraction_id = :extraction AND position = :position AND revision = :revision
               AND EXISTS (SELECT 1 FROM ai_extractions
                           WHERE id = :extraction AND home_id = :home)',
            [
                'decision' => $decision,
                'actor' => $actorUserId,
                'reviewed' => $this->date($at),
                'extraction' => $extractionId,
                'position' => $position,
                'revision' => $expectedRevision,
                'home' => $homeId,
            ],
        ) === 1;
    }

    public function hasBlockingExtractionDiscrepancies(string $homeId, string $extractionId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ai_extraction_discrepancies d
             WHERE d.extraction_id = :extraction AND d.review_status IN (:pending, :rejected)
               AND EXISTS (SELECT 1 FROM ai_extractions e
                           WHERE e.id = d.extraction_id AND e.home_id = :home)',
            [
                'extraction' => $extractionId,
                'pending' => 'pending',
                'rejected' => 'rejected_extraction',
                'home' => $homeId,
            ],
        ) > 0;
    }

    public function mediaQuota(string $homeId, int $defaultBytes): int
    {
        $value = $this->connection->fetchOne(
            'SELECT quota_bytes FROM ai_media_quotas WHERE home_id = :home',
            ['home' => $homeId],
        );

        return $value === false ? $defaultBytes : (int) $value;
    }

    public function mediaUsage(string $homeId): int
    {
        $value = $this->connection->fetchOne(
            'SELECT used_bytes FROM ai_media_quotas WHERE home_id = :home',
            ['home' => $homeId],
        );

        return $value === false ? 0 : (int) $value;
    }

    public function reserveMediaBytes(
        string $homeId,
        int $bytes,
        int $defaultQuotaBytes,
        DateTimeImmutable $at,
    ): bool {
        if ($bytes < 1 || $bytes > $defaultQuotaBytes) {
            return false;
        }
        $now = $this->date($at);
        try {
            $this->connection->insert('ai_media_quotas', [
                'home_id' => $homeId,
                'quota_bytes' => $defaultQuotaBytes,
                'used_bytes' => $bytes,
                'revision' => 1,
                'updated_at' => $now,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return $this->connection->executeStatement(
                'UPDATE ai_media_quotas SET used_bytes = used_bytes + :bytes,
                        revision = revision + 1, updated_at = :updated
                 WHERE home_id = :home AND used_bytes <= quota_bytes - :bytes',
                ['bytes' => $bytes, 'updated' => $now, 'home' => $homeId],
            ) === 1;
        }
    }

    public function releaseMediaBytes(string $homeId, int $bytes, DateTimeImmutable $at): void
    {
        if ($bytes < 1) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE ai_media_quotas SET used_bytes = CASE
                    WHEN used_bytes >= :bytes THEN used_bytes - :bytes ELSE 0 END,
                    revision = revision + 1, updated_at = :updated WHERE home_id = :home',
            ['bytes' => $bytes, 'updated' => $this->date($at), 'home' => $homeId],
        );
    }

    public function activeMediaByDigest(string $homeId, string $sha256): ?array
    {
        return $this->one(
            'SELECT id, mime_type AS mimeType, plaintext_bytes AS plaintextBytes,
                    retention, processing_status AS processingStatus
             FROM ai_media_assets WHERE home_id = :home AND sha256 = :digest
               AND deleted_at IS NULL ORDER BY created_at LIMIT 1',
            ['home' => $homeId, 'digest' => $sha256],
        );
    }

    private function insertMediaRecord(
        string $id,
        string $homeId,
        ?string $sourceAssetId,
        string $retention,
        string $purpose,
        string $mimeType,
        ?string $originalName,
        EncryptedMediaObject $object,
        ?int $durationMs,
        ?int $frameOffsetMs,
        string $processingStatus,
        string $actorUserId,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): bool {
        $now = $this->date($at);
        try {
            $this->connection->insert('ai_media_assets', [
                'id' => $id,
                'home_id' => $homeId,
                'source_asset_id' => $sourceAssetId,
                'retention' => $retention,
                'purpose' => $purpose,
                'mime_type' => $mimeType,
                'original_name' => $originalName,
                'object_key' => $object->objectKey,
                'wrapped_key' => $object->wrappedKey,
                'wrap_nonce' => $object->wrapNonce,
                'key_version' => $object->keyVersion,
                'sha256' => $object->sha256,
                'plaintext_bytes' => $object->plaintextBytes,
                'duration_ms' => $durationMs,
                'frame_offset_ms' => $frameOffsetMs,
                'processing_status' => $processingStatus,
                'processing_error' => null,
                'active_key' => 'active',
                'revision' => 1,
                'created_by_user_id' => $actorUserId,
                'expires_at' => $expiresAt === null ? null : $this->date($expiresAt),
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function insertMediaWithinQuota(
        string $id,
        string $homeId,
        ?string $sourceAssetId,
        string $retention,
        string $purpose,
        string $mimeType,
        ?string $originalName,
        EncryptedMediaObject $object,
        ?int $durationMs,
        ?int $frameOffsetMs,
        string $processingStatus,
        string $actorUserId,
        ?DateTimeImmutable $expiresAt,
        int $defaultQuotaBytes,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->transactional(function () use (
            $id,
            $homeId,
            $sourceAssetId,
            $retention,
            $purpose,
            $mimeType,
            $originalName,
            $object,
            $durationMs,
            $frameOffsetMs,
            $processingStatus,
            $actorUserId,
            $expiresAt,
            $defaultQuotaBytes,
            $at,
        ): bool {
            if (! $this->reserveMediaBytes($homeId, $object->plaintextBytes, $defaultQuotaBytes, $at)) {
                return false;
            }
            $inserted = $this->insertMediaRecord(
                $id,
                $homeId,
                $sourceAssetId,
                $retention,
                $purpose,
                $mimeType,
                $originalName,
                $object,
                $durationMs,
                $frameOffsetMs,
                $processingStatus,
                $actorUserId,
                $expiresAt,
                $at,
            );
            if (! $inserted) {
                $this->releaseMediaBytes($homeId, $object->plaintextBytes, $at);
            }

            return $inserted;
        });
    }

    public function media(string $homeId, string $assetId): ?array
    {
        return $this->one(
            'SELECT id, home_id AS homeId, source_asset_id AS sourceAssetId, retention, purpose,
                    mime_type AS mimeType, original_name AS originalName, object_key AS objectKey,
                    wrapped_key AS wrappedKey, wrap_nonce AS wrapNonce, key_version AS keyVersion,
                    sha256, plaintext_bytes AS plaintextBytes, duration_ms AS durationMs,
                    frame_offset_ms AS frameOffsetMs, processing_status AS processingStatus,
                    processing_error AS processingError, created_by_user_id AS createdByUserId,
                    expires_at AS expiresAt, revision, created_at AS createdAt, updated_at AS updatedAt
             FROM ai_media_assets WHERE home_id = :home AND id = :id AND deleted_at IS NULL',
            ['home' => $homeId, 'id' => $assetId],
        );
    }

    public function listMedia(string $homeId, int $limit, ?string $beforeId = null): array
    {
        $sql = 'SELECT id, source_asset_id AS sourceAssetId, retention, purpose, mime_type AS mimeType,
                       original_name AS originalName, sha256, plaintext_bytes AS plaintextBytes,
                       duration_ms AS durationMs, frame_offset_ms AS frameOffsetMs,
                       processing_status AS processingStatus, processing_error AS processingError,
                       expires_at AS expiresAt, revision, created_at AS createdAt
                FROM ai_media_assets WHERE home_id = :home AND deleted_at IS NULL';
        $parameters = ['home' => $homeId];
        if ($beforeId !== null) {
            $sql .= ' AND id < :before';
            $parameters['before'] = $beforeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit));

        return $this->connection->fetchAllAssociative($sql, $parameters);
    }

    public function derivedMedia(string $homeId, string $sourceAssetId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, home_id AS homeId, object_key AS objectKey, wrapped_key AS wrappedKey,
                    wrap_nonce AS wrapNonce, key_version AS keyVersion, sha256,
                    plaintext_bytes AS plaintextBytes
             FROM ai_media_assets WHERE home_id = :home AND source_asset_id = :source
               AND deleted_at IS NULL ORDER BY frame_offset_ms, id',
            ['home' => $homeId, 'source' => $sourceAssetId],
        );
    }

    public function markMediaDeleted(string $homeId, string $assetId, DateTimeImmutable $at): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ai_media_assets SET deleted_at = :deleted, processing_status = :status,
                    active_key = NULL, updated_at = :updated
             WHERE home_id = :home AND id = :id AND deleted_at IS NULL',
            [
                'deleted' => $this->date($at),
                'status' => 'deleted',
                'updated' => $this->date($at),
                'home' => $homeId,
                'id' => $assetId,
            ],
        ) === 1;
    }

    public function deleteMediaWithinQuota(
        string $homeId,
        string $assetId,
        int $plaintextBytes,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->transactional(function () use (
            $homeId,
            $assetId,
            $plaintextBytes,
            $at,
        ): bool {
            if (! $this->markMediaDeleted($homeId, $assetId, $at)) {
                return false;
            }
            $this->releaseMediaBytes($homeId, $plaintextBytes, $at);

            return true;
        });
    }

    public function updateMediaRetention(
        string $homeId,
        string $assetId,
        string $retention,
        ?DateTimeImmutable $expiresAt,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE ai_media_assets SET retention = :retention, expires_at = :expires,
                    revision = revision + 1, updated_at = :updated
             WHERE home_id = :home AND id = :id AND revision = :revision AND deleted_at IS NULL',
            [
                'retention' => $retention,
                'expires' => $expiresAt === null ? null : $this->date($expiresAt),
                'updated' => $this->date($at),
                'home' => $homeId,
                'id' => $assetId,
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function updateDerivedMediaRetention(
        string $homeId,
        string $sourceAssetId,
        string $retention,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): void {
        $this->connection->executeStatement(
            'UPDATE ai_media_assets SET retention = :retention, expires_at = :expires,
                    revision = revision + 1, updated_at = :updated
             WHERE home_id = :home AND source_asset_id = :source AND deleted_at IS NULL',
            [
                'retention' => $retention,
                'expires' => $expiresAt === null ? null : $this->date($expiresAt),
                'updated' => $this->date($at),
                'home' => $homeId,
                'source' => $sourceAssetId,
            ],
        );
    }

    public function claimQueuedVideo(DateTimeImmutable $at): ?array
    {
        $row = $this->one(
            'SELECT id, home_id AS homeId FROM ai_media_assets
             WHERE purpose = :purpose AND processing_status = :status AND deleted_at IS NULL
             ORDER BY created_at, id LIMIT 1',
            ['purpose' => 'video', 'status' => 'queued'],
        );
        if ($row === null) {
            return null;
        }
        $updated = $this->connection->executeStatement(
            'UPDATE ai_media_assets SET processing_status = :processing, revision = revision + 1,
                    updated_at = :updated
             WHERE id = :id AND home_id = :home AND processing_status = :queued',
            [
                'processing' => 'processing',
                'updated' => $this->date($at),
                'id' => $row['id'],
                'home' => $row['homeId'],
                'queued' => 'queued',
            ],
        );

        return $updated === 1 ? $this->media((string) $row['homeId'], (string) $row['id']) : null;
    }

    public function finishVideo(
        string $homeId,
        string $assetId,
        ?int $durationMs,
        ?string $error,
        DateTimeImmutable $at,
    ): void {
        $this->connection->executeStatement(
            'UPDATE ai_media_assets SET processing_status = :status, processing_error = :error,
                    duration_ms = :duration, revision = revision + 1, updated_at = :updated
             WHERE home_id = :home AND id = :id AND processing_status = :processing',
            [
                'status' => $error === null ? 'ready' : 'failed',
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'duration' => $durationMs,
                'updated' => $this->date($at),
                'home' => $homeId,
                'id' => $assetId,
                'processing' => 'processing',
            ],
        );
    }

    public function expiredMedia(DateTimeImmutable $at, int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, home_id AS homeId, object_key AS objectKey, wrapped_key AS wrappedKey,
                    wrap_nonce AS wrapNonce, key_version AS keyVersion, sha256,
                    plaintext_bytes AS plaintextBytes
             FROM ai_media_assets WHERE expires_at IS NOT NULL AND expires_at <= :at
               AND deleted_at IS NULL ORDER BY expires_at LIMIT ' . max(1, min(500, $limit)),
            ['at' => $this->date($at)],
        );
    }

    public function recordObservationDecision(
        string $id,
        string $homeId,
        ?string $extractionId,
        string $type,
        string $leftReference,
        string $rightReference,
        array $evidence,
        string $decision,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $this->connection->insert('ai_observation_decisions', [
            'id' => $id,
            'home_id' => $homeId,
            'extraction_id' => $extractionId,
            'decision_type' => $type,
            'left_reference' => $leftReference,
            'right_reference' => $rightReference,
            'evidence_json' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'decision' => $decision,
            'revision' => 1,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function observationDecisions(string $homeId, string $extractionId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, decision_type AS decisionType, left_reference AS leftReference,
                    right_reference AS rightReference, evidence_json AS evidenceJson,
                    decision, revision, reviewed_by_user_id AS reviewedByUserId,
                    reviewed_at AS reviewedAt, created_at AS createdAt
             FROM ai_observation_decisions WHERE home_id = :home AND extraction_id = :extraction
             ORDER BY created_at, id',
            ['home' => $homeId, 'extraction' => $extractionId],
        );
        foreach ($rows as &$row) {
            $row['evidence'] = json_decode((string) $row['evidenceJson'], true, 32, JSON_THROW_ON_ERROR);
            unset($row['evidenceJson']);
        }
        unset($row);

        return $rows;
    }

    public function hasPendingObservationDecisions(string $homeId, string $extractionId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ai_observation_decisions
             WHERE home_id = :home AND extraction_id = :extraction AND decision = :pending',
            ['home' => $homeId, 'extraction' => $extractionId, 'pending' => 'pending'],
        ) > 0;
    }

    public function candidateIsConfirmedDuplicate(string $homeId, string $extractionId, int $position): bool
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT evidence_json FROM ai_observation_decisions
             WHERE home_id = :home AND extraction_id = :extraction
               AND decision_type = :type AND decision = :decision',
            [
                'home' => $homeId,
                'extraction' => $extractionId,
                'type' => 'visual_overlap',
                'decision' => 'confirmed_duplicate',
            ],
        );
        foreach ($rows as $encoded) {
            $evidence = json_decode((string) $encoded, true, 16, JSON_THROW_ON_ERROR);
            if (is_array($evidence) && (int) ($evidence['rightCandidatePosition'] ?? -1) === $position) {
                return true;
            }
        }

        return false;
    }

    public function reviewObservationDecision(
        string $homeId,
        string $id,
        string $decision,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE ai_observation_decisions SET decision = :decision, revision = revision + 1,
                    reviewed_by_user_id = :actor, reviewed_at = :reviewed, updated_at = :updated
             WHERE home_id = :home AND id = :id AND revision = :revision',
            [
                'decision' => $decision,
                'actor' => $actorUserId,
                'reviewed' => $this->date($at),
                'updated' => $this->date($at),
                'home' => $homeId,
                'id' => $id,
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $parameters): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $parameters);

        return $row === false ? null : $row;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function tableExists(string $table): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$table]);
    }
}
