<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804001400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revisioned AI provider orchestration and encrypted private-media lifecycle';
    }

    public function up(Schema $schema): void
    {
        $profiles = $schema->createTable('ai_provider_profiles');
        $this->homeEntity($profiles);
        $profiles->addColumn('label', Types::STRING, ['length' => 80]);
        $profiles->addColumn('provider', Types::STRING, ['length' => 40]);
        $profiles->addColumn('model', Types::STRING, ['length' => 120]);
        $profiles->addColumn('ciphertext', Types::TEXT, ['notnull' => false]);
        $profiles->addColumn('nonce', Types::STRING, ['length' => 64, 'notnull' => false]);
        $profiles->addColumn('key_version', Types::INTEGER, ['notnull' => false]);
        $profiles->addColumn('last_four', Types::STRING, ['length' => 4, 'notnull' => false]);
        $profiles->addColumn('estimated_cost_micros', Types::BIGINT, ['default' => 0]);
        $profiles->addColumn('status', Types::STRING, ['length' => 24]);
        $profiles->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $profiles->addColumn('updated_by_user_id', Types::STRING, ['length' => 36]);
        $this->timestamps($profiles);
        $profiles->addUniqueIndex(['home_id', 'label'], 'uniq_ai_profile_home_label');
        $profiles->addIndex(['home_id', 'status', 'provider'], 'idx_ai_profile_home_status');

        $policies = $schema->createTable('ai_orchestration_policies');
        $policies->addColumn('home_id', Types::STRING, ['length' => 36]);
        $policies->addColumn('extraction_profile_ids_json', Types::TEXT);
        $policies->addColumn('validation_profile_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $policies->addColumn('max_attempts', Types::INTEGER);
        $policies->addColumn('max_total_tokens', Types::INTEGER);
        $policies->addColumn('max_estimated_cost_micros', Types::BIGINT);
        $policies->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $policies->addColumn('updated_by_user_id', Types::STRING, ['length' => 36]);
        $this->timestamps($policies);
        $policies->setPrimaryKey(['home_id']);
        $policies->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);

        $attempts = $schema->createTable('ai_extraction_attempts');
        $attempts->addColumn('extraction_id', Types::STRING, ['length' => 36]);
        $attempts->addColumn('position', Types::INTEGER);
        $attempts->addColumn('purpose', Types::STRING, ['length' => 16]);
        $attempts->addColumn('observation_index', Types::INTEGER, ['default' => 0]);
        $attempts->addColumn('profile_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $attempts->addColumn('provider', Types::STRING, ['length' => 40]);
        $attempts->addColumn('model', Types::STRING, ['length' => 120]);
        $attempts->addColumn('status', Types::STRING, ['length' => 24]);
        $attempts->addColumn('error_code', Types::STRING, ['length' => 64, 'notnull' => false]);
        $attempts->addColumn('estimated_cost_micros', Types::BIGINT, ['default' => 0]);
        $attempts->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $attempts->setPrimaryKey(['extraction_id', 'position']);
        $attempts->addForeignKeyConstraint('ai_extractions', ['extraction_id'], ['id'], ['onDelete' => 'CASCADE']);

        $discrepancies = $schema->createTable('ai_extraction_discrepancies');
        $discrepancies->addColumn('extraction_id', Types::STRING, ['length' => 36]);
        $discrepancies->addColumn('position', Types::INTEGER);
        $discrepancies->addColumn('observation_index', Types::INTEGER, ['default' => 0]);
        $discrepancies->addColumn('payload_json', Types::TEXT);
        $discrepancies->addColumn('review_status', Types::STRING, ['length' => 24]);
        $discrepancies->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $discrepancies->addColumn('reviewed_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $discrepancies->addColumn('reviewed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $discrepancies->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $discrepancies->setPrimaryKey(['extraction_id', 'position']);
        $discrepancies->addForeignKeyConstraint('ai_extractions', ['extraction_id'], ['id'], ['onDelete' => 'CASCADE']);

        $quotas = $schema->createTable('ai_media_quotas');
        $quotas->addColumn('home_id', Types::STRING, ['length' => 36]);
        $quotas->addColumn('quota_bytes', Types::BIGINT);
        $quotas->addColumn('used_bytes', Types::BIGINT, ['default' => 0]);
        $quotas->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $quotas->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $quotas->setPrimaryKey(['home_id']);
        $quotas->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);

        $media = $schema->createTable('ai_media_assets');
        $this->homeEntity($media);
        $media->addColumn('source_asset_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $media->addColumn('retention', Types::STRING, ['length' => 16]);
        $media->addColumn('purpose', Types::STRING, ['length' => 24]);
        $media->addColumn('mime_type', Types::STRING, ['length' => 80]);
        $media->addColumn('original_name', Types::STRING, ['length' => 191, 'notnull' => false]);
        $media->addColumn('object_key', Types::STRING, ['length' => 191]);
        $media->addColumn('wrapped_key', Types::TEXT);
        $media->addColumn('wrap_nonce', Types::STRING, ['length' => 64]);
        $media->addColumn('key_version', Types::INTEGER);
        $media->addColumn('sha256', Types::STRING, ['length' => 64]);
        $media->addColumn('plaintext_bytes', Types::BIGINT);
        $media->addColumn('duration_ms', Types::INTEGER, ['notnull' => false]);
        $media->addColumn('frame_offset_ms', Types::INTEGER, ['notnull' => false]);
        $media->addColumn('processing_status', Types::STRING, ['length' => 24]);
        $media->addColumn('processing_error', Types::STRING, ['length' => 500, 'notnull' => false]);
        $media->addColumn('active_key', Types::STRING, ['length' => 8, 'notnull' => false, 'default' => 'active']);
        $media->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $media->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $media->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $media->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->timestamps($media);
        $media->addIndex(['home_id', 'deleted_at', 'created_at'], 'idx_ai_media_home_active');
        $media->addIndex(['home_id', 'sha256', 'deleted_at'], 'idx_ai_media_home_digest');
        $media->addUniqueIndex(['home_id', 'sha256', 'active_key'], 'uniq_ai_media_active_digest');
        $media->addIndex(['processing_status', 'created_at'], 'idx_ai_media_video_work');
        $media->addIndex(['source_asset_id', 'frame_offset_ms'], 'idx_ai_media_source_frames');

        $observations = $schema->createTable('ai_observation_decisions');
        $this->homeEntity($observations);
        $observations->addColumn('extraction_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $observations->addColumn('decision_type', Types::STRING, ['length' => 24]);
        $observations->addColumn('left_reference', Types::STRING, ['length' => 191]);
        $observations->addColumn('right_reference', Types::STRING, ['length' => 191]);
        $observations->addColumn('evidence_json', Types::TEXT);
        $observations->addColumn('decision', Types::STRING, ['length' => 24]);
        $observations->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $observations->addColumn('reviewed_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $observations->addColumn('reviewed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->timestamps($observations);
        $observations->addIndex(['home_id', 'extraction_id', 'decision'], 'idx_ai_observation_review');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('ai_observation_decisions');
        $schema->dropTable('ai_media_assets');
        $schema->dropTable('ai_media_quotas');
        $schema->dropTable('ai_extraction_discrepancies');
        $schema->dropTable('ai_extraction_attempts');
        $schema->dropTable('ai_orchestration_policies');
        $schema->dropTable('ai_provider_profiles');
    }

    private function homeEntity(Table $table): void
    {
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->addColumn('home_id', Types::STRING, ['length' => 36]);
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    private function timestamps(Table $table): void
    {
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
    }
}
