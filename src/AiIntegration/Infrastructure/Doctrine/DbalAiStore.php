<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Providentia\AiIntegration\Application\AiStore;

final class DbalAiStore implements AiStore
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
        if ($targetId === null || $targetId === '') {
            return $kind === 'stock';
        }
        $table = $kind === 'receipt' ? 'receipts' : 'stock_count_sessions';
        $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE id = :id AND home_id = :home';

        return (int) $this->connection->fetchOne($sql, ['id' => $targetId, 'home' => $homeId]) === 1;
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
}
