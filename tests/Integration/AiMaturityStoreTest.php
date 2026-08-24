<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\Media\EncryptedMediaObject;
use Providentia\AiIntegration\Infrastructure\Doctrine\DbalAiStore;

// phpcs:disable PSR2.Methods.FunctionCallSignature.MultipleArguments -- dense persistence fixtures stay readable.
final class AiMaturityStoreTest extends TestCase
{
    private Connection $connection;
    private DbalAiStore $store;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ($this->schema() as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->store = new DbalAiStore($this->connection);
        $this->at = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
    }

    public function testProfilesPoliciesAndExecutionEvidenceAreRevisionedAndPersisted(): void
    {
        self::assertTrue($this->store->saveProviderProfile([
            'id' => 'profile-1', 'homeId' => 'home-1', 'label' => 'OpenAI primary',
            'provider' => 'openai', 'model' => 'vision', 'ciphertext' => 'cipher',
            'nonce' => 'nonce', 'keyVersion' => 1, 'lastFour' => '1234',
            'estimatedCostMicros' => 250, 'actorUserId' => 'user-1',
        ], 0, $this->at));
        self::assertFalse($this->store->saveProviderProfile([
            'id' => 'profile-1', 'homeId' => 'home-1', 'label' => 'OpenAI primary',
            'provider' => 'openai', 'model' => 'vision', 'ciphertext' => 'cipher',
            'nonce' => 'nonce', 'keyVersion' => 1, 'lastFour' => '1234',
            'estimatedCostMicros' => 250, 'actorUserId' => 'user-1',
        ], 0, $this->at));
        self::assertTrue($this->store->saveOrchestrationPolicy(
            'home-1', ['profile-1'], null, 2, 50000, 1000, 0, 'user-1', $this->at,
        ));
        $policy = $this->store->orchestrationPolicy('home-1');
        self::assertNotNull($policy);
        self::assertSame(['profile-1'], $policy['extractionProfileIds']);

        $this->connection->insert('ai_extractions', ['id' => 'extraction-1', 'home_id' => 'home-1']);
        $this->store->appendExtractionAttempt('extraction-1', 0, 0, [
            'purpose' => 'extract', 'profileId' => 'profile-1', 'provider' => 'openai',
            'model' => 'vision', 'status' => 'completed', 'errorCode' => null,
            'estimatedCostMicros' => 250,
        ], $this->at);
        $this->store->appendExtractionDiscrepancies(
            'extraction-1', 0, 0, [['type' => 'field', 'field' => 'merchant']], $this->at,
        );
        self::assertSame(1, $this->tableCount('ai_extraction_attempts'));
        self::assertSame(1, $this->tableCount('ai_extraction_discrepancies'));
        self::assertTrue($this->store->hasBlockingExtractionDiscrepancies('home-1', 'extraction-1'));
        self::assertTrue($this->store->reviewExtractionDiscrepancy(
            'home-1', 'extraction-1', 0, 'accepted_primary', 1, 'user-1', $this->at,
        ));
        self::assertFalse($this->store->hasBlockingExtractionDiscrepancies('home-1', 'extraction-1'));
    }

    public function testProfileCredentialRevocationClearsAllSecretsWithCasAndSanitizedAudit(): void
    {
        self::assertTrue($this->store->saveProviderProfile([
            'id' => 'profile-1', 'homeId' => 'home-1', 'label' => 'OpenAI primary',
            'provider' => 'openai', 'model' => 'vision', 'ciphertext' => 'cipher',
            'nonce' => 'nonce', 'keyVersion' => 1, 'lastFour' => '1234',
            'estimatedCostMicros' => 250, 'actorUserId' => 'user-1',
        ], 0, $this->at));

        self::assertTrue($this->connection->transactional(
            fn (): bool => $this->store->revokeProviderProfileCredential(
                'audit-1', 'home-1', 'profile-1', 1, 'user-1', $this->at,
            ),
        ));
        $profile = $this->connection->fetchAssociative(
            'SELECT ciphertext, nonce, key_version AS keyVersion, last_four AS lastFour, revision
             FROM ai_provider_profiles WHERE id = :id',
            ['id' => 'profile-1'],
        );
        self::assertIsArray($profile);
        self::assertNull($profile['ciphertext']);
        self::assertNull($profile['nonce']);
        self::assertNull($profile['keyVersion']);
        self::assertNull($profile['lastFour']);
        self::assertSame(2, (int) $profile['revision']);
        $audit = $this->connection->fetchAssociative(
            'SELECT action, details FROM audit_events WHERE id = :id',
            ['id' => 'audit-1'],
        );
        self::assertIsArray($audit);
        self::assertSame('ai.provider-profile.credential-revoked', $audit['action']);
        self::assertSame([
            'expectedRevision' => 1,
            'revision' => 2,
            'credentialConfigured' => false,
        ], json_decode((string) $audit['details'], true, 8, JSON_THROW_ON_ERROR));
        self::assertFalse($this->store->revokeProviderProfileCredential(
            'audit-stale', 'home-1', 'profile-1', 1, 'user-1', $this->at,
        ));
        self::assertFalse($this->store->revokeProviderProfileCredential(
            'audit-wrong-home', 'home-2', 'profile-1', 2, 'user-1', $this->at,
        ));
        self::assertSame(1, $this->tableCount('audit_events'));
    }

    public function testProfileCredentialRevocationRollsBackWhenItsAuditCannotBeWritten(): void
    {
        self::assertTrue($this->store->saveProviderProfile([
            'id' => 'profile-1', 'homeId' => 'home-1', 'label' => 'OpenAI primary',
            'provider' => 'openai', 'model' => 'vision', 'ciphertext' => 'cipher',
            'nonce' => 'nonce', 'keyVersion' => 1, 'lastFour' => '1234',
            'estimatedCostMicros' => 250, 'actorUserId' => 'user-1',
        ], 0, $this->at));
        $this->connection->insert('audit_events', [
            'id' => 'audit-conflict',
            'action' => 'existing',
        ]);

        try {
            $this->connection->transactional(
                fn (): bool => $this->store->revokeProviderProfileCredential(
                    'audit-conflict', 'home-1', 'profile-1', 1, 'user-1', $this->at,
                ),
            );
            self::fail('The credential clear committed without its audit event.');
        } catch (UniqueConstraintViolationException) {
            // Expected: the duplicate audit identifier must abort the transaction.
        }

        $profile = $this->connection->fetchAssociative(
            'SELECT ciphertext, nonce, key_version AS keyVersion, last_four AS lastFour, revision
             FROM ai_provider_profiles WHERE id = :id',
            ['id' => 'profile-1'],
        );
        self::assertIsArray($profile);
        self::assertSame('cipher', $profile['ciphertext']);
        self::assertSame('nonce', $profile['nonce']);
        self::assertSame(1, (int) $profile['keyVersion']);
        self::assertSame('1234', $profile['lastFour']);
        self::assertSame(1, (int) $profile['revision']);
    }

    public function testMediaQuotaDigestLifecycleAndDuplicateReviewRemainHomeScoped(): void
    {
        $object = new EncryptedMediaObject('object', 'wrapped', 'nonce', 1, str_repeat('a', 64), 128);
        self::assertTrue($this->store->insertMediaWithinQuota(
            'asset-1', 'home-1', null, 'retained', 'image', 'image/jpeg', 'pantry.jpg',
            $object, null, null, 'ready', 'user-1', null, 1024, $this->at,
        ));
        self::assertSame(128, $this->store->mediaUsage('home-1'));
        $stored = $this->store->activeMediaByDigest('home-1', str_repeat('a', 64));
        self::assertNotNull($stored);
        self::assertSame('asset-1', $stored['id']);
        self::assertNull($this->store->activeMediaByDigest('home-2', str_repeat('a', 64)));
        $duplicate = new EncryptedMediaObject('duplicate', 'wrapped', 'nonce', 1, str_repeat('a', 64), 128);
        self::assertFalse($this->store->insertMediaWithinQuota(
            'asset-duplicate', 'home-1', null, 'retained', 'image', 'image/jpeg', null,
            $duplicate, null, null, 'ready', 'user-1', null, 1024, $this->at,
        ));
        self::assertSame(128, $this->store->mediaUsage('home-1'), 'A failed insert must roll back its reservation.');

        $this->store->recordObservationDecision(
            'decision-1', 'home-1', 'extraction-1', 'visual_overlap', 'left', 'right',
            ['normalizedCandidateKey' => 'key'], 'pending', $this->at,
        );
        self::assertTrue($this->store->reviewObservationDecision(
            'home-1', 'decision-1', 'confirmed_duplicate', 1, 'user-1', $this->at,
        ));
        self::assertFalse($this->store->reviewObservationDecision(
            'home-1', 'decision-1', 'distinct', 1, 'user-1', $this->at,
        ));
        $tooLarge = new EncryptedMediaObject('second', 'wrapped', 'nonce', 1, str_repeat('b', 64), 900);
        self::assertFalse($this->store->insertMediaWithinQuota(
            'asset-2', 'home-1', null, 'retained', 'image', 'image/jpeg', null,
            $tooLarge, null, null, 'ready', 'user-1', null, 1024, $this->at,
        ));
        self::assertSame(128, $this->store->mediaUsage('home-1'));
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ai_media_assets WHERE id = 'asset-2'",
        ));

        self::assertTrue($this->store->deleteMediaWithinQuota('home-1', 'asset-1', 128, $this->at));
        self::assertSame(0, $this->store->mediaUsage('home-1'));
    }

    /** @return list<string> */
    private function schema(): array
    {
        return [
            'CREATE TABLE ai_extractions (id TEXT PRIMARY KEY, home_id TEXT)',
            'CREATE TABLE ai_provider_profiles (id TEXT PRIMARY KEY, home_id TEXT, label TEXT, provider TEXT,
                model TEXT, ciphertext TEXT NULL, nonce TEXT NULL, key_version INTEGER NULL, last_four TEXT NULL,
                estimated_cost_micros INTEGER, status TEXT, revision INTEGER, updated_by_user_id TEXT,
                created_at TEXT, updated_at TEXT, UNIQUE(home_id, label))',
            'CREATE TABLE ai_orchestration_policies (home_id TEXT PRIMARY KEY, extraction_profile_ids_json TEXT,
                validation_profile_id TEXT NULL, max_attempts INTEGER, max_total_tokens INTEGER,
                max_estimated_cost_micros INTEGER, revision INTEGER, updated_by_user_id TEXT,
                created_at TEXT, updated_at TEXT)',
            'CREATE TABLE ai_extraction_attempts (extraction_id TEXT, position INTEGER, purpose TEXT,
                observation_index INTEGER, profile_id TEXT NULL, provider TEXT, model TEXT, status TEXT,
                error_code TEXT NULL, estimated_cost_micros INTEGER, created_at TEXT,
                PRIMARY KEY(extraction_id, position))',
            'CREATE TABLE ai_extraction_discrepancies (extraction_id TEXT, position INTEGER,
                observation_index INTEGER, payload_json TEXT, review_status TEXT, revision INTEGER,
                reviewed_by_user_id TEXT NULL, reviewed_at TEXT NULL, created_at TEXT,
                PRIMARY KEY(extraction_id, position))',
            'CREATE TABLE ai_media_quotas (home_id TEXT PRIMARY KEY, quota_bytes INTEGER, used_bytes INTEGER,
                revision INTEGER,
                updated_at TEXT)',
            'CREATE TABLE ai_media_assets (id TEXT PRIMARY KEY, home_id TEXT, source_asset_id TEXT NULL,
                retention TEXT, purpose TEXT, mime_type TEXT, original_name TEXT NULL, object_key TEXT,
                wrapped_key TEXT, wrap_nonce TEXT, key_version INTEGER, sha256 TEXT, plaintext_bytes INTEGER,
                duration_ms INTEGER NULL, frame_offset_ms INTEGER NULL, processing_status TEXT,
                processing_error TEXT NULL, created_by_user_id TEXT, expires_at TEXT NULL, deleted_at TEXT NULL,
                active_key TEXT NULL DEFAULT \'active\', revision INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT,
                UNIQUE(home_id, sha256, active_key))',
            'CREATE TABLE ai_observation_decisions (id TEXT PRIMARY KEY, home_id TEXT, extraction_id TEXT NULL,
                decision_type TEXT, left_reference TEXT, right_reference TEXT, evidence_json TEXT,
                decision TEXT, revision INTEGER, reviewed_by_user_id TEXT NULL, reviewed_at TEXT NULL,
                created_at TEXT, updated_at TEXT)',
            'CREATE TABLE audit_events (
                id TEXT PRIMARY KEY, home_id TEXT NULL, actor_user_id TEXT NULL,
                action TEXT, target_type TEXT, target_id TEXT, details TEXT, occurred_at TEXT
            )',
        ];
    }

    private function tableCount(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }
}
