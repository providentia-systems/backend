<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 6 privacy-controlled AI settings, encrypted credentials and extraction review';
    }

    public function up(Schema $schema): void
    {
        $settings = $schema->createTable('ai_settings');
        $settings->addColumn('home_id', Types::STRING, ['length' => 36]);
        $settings->addColumn('mode', Types::STRING, ['length' => 24]);
        $settings->addColumn('provider', Types::STRING, ['length' => 40, 'notnull' => false]);
        $settings->addColumn('model', Types::STRING, ['length' => 120, 'notnull' => false]);
        $settings->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $settings->addColumn('updated_by_user_id', Types::STRING, ['length' => 36]);
        $this->timestamps($settings);
        $settings->setPrimaryKey(['home_id']);
        $settings->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);

        $credentials = $schema->createTable('ai_provider_credentials');
        $this->homeId($credentials);
        $credentials->addColumn('provider', Types::STRING, ['length' => 40]);
        $credentials->addColumn('ciphertext', Types::TEXT);
        $credentials->addColumn('nonce', Types::STRING, ['length' => 64]);
        $credentials->addColumn('key_version', Types::INTEGER);
        $credentials->addColumn('last_four', Types::STRING, ['length' => 4]);
        $credentials->addColumn('status', Types::STRING, ['length' => 24]);
        $credentials->addColumn('rotated_by_user_id', Types::STRING, ['length' => 36]);
        $this->timestamps($credentials);
        $credentials->addUniqueIndex(['home_id', 'provider'], 'uniq_ai_credentials_home_provider');

        $extractions = $schema->createTable('ai_extractions');
        $this->homeId($extractions);
        $extractions->addColumn('kind', Types::STRING, ['length' => 24]);
        $extractions->addColumn('target_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $extractions->addColumn('provider', Types::STRING, ['length' => 40]);
        $extractions->addColumn('model', Types::STRING, ['length' => 120]);
        $extractions->addColumn('status', Types::STRING, ['length' => 24]);
        $extractions->addColumn('input_mime_type', Types::STRING, ['length' => 40]);
        $extractions->addColumn('input_sha256', Types::STRING, ['length' => 64]);
        $extractions->addColumn('input_byte_count', Types::INTEGER);
        $extractions->addColumn('schema_version', Types::INTEGER);
        $extractions->addColumn('prompt_template_version', Types::INTEGER);
        $extractions->addColumn('processing_ms', Types::INTEGER, ['notnull' => false]);
        $extractions->addColumn('usage_json', Types::TEXT, ['notnull' => false]);
        $extractions->addColumn('result_json', Types::TEXT, ['notnull' => false]);
        $extractions->addColumn('error_code', Types::STRING, ['length' => 64, 'notnull' => false]);
        $extractions->addColumn('error_detail', Types::STRING, ['length' => 500, 'notnull' => false]);
        $extractions->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $extractions->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->timestamps($extractions);
        $extractions->addIndex(['home_id', 'status', 'created_at'], 'idx_ai_extractions_home_status');
        $extractions->addIndex(['home_id', 'kind', 'target_id'], 'idx_ai_extractions_target');
        $extractions->addIndex(['home_id', 'input_sha256'], 'idx_ai_extractions_digest');
        $extractions->addUniqueIndex(['id', 'home_id'], 'uniq_ai_extractions_id_home');

        $candidates = $schema->createTable('ai_extraction_candidates');
        $candidates->addColumn('home_id', Types::STRING, ['length' => 36]);
        $candidates->addColumn('extraction_id', Types::STRING, ['length' => 36]);
        $candidates->addColumn('position', Types::INTEGER);
        $candidates->addColumn('candidate_type', Types::STRING, ['length' => 32]);
        $candidates->addColumn('payload_json', Types::TEXT);
        $candidates->addColumn('confidence', Types::DECIMAL, [
            'precision' => 5,
            'scale' => 4,
            'notnull' => false,
        ]);
        $candidates->addColumn('review_status', Types::STRING, ['length' => 24]);
        $candidates->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $candidates->addColumn('reviewed_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $candidates->addColumn('reviewed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->timestamps($candidates);
        $candidates->setPrimaryKey(['extraction_id', 'position']);
        $candidates->addForeignKeyConstraint(
            'ai_extractions',
            ['extraction_id', 'home_id'],
            ['id', 'home_id'],
            ['onDelete' => 'CASCADE'],
        );
        $candidates->addIndex(
            ['home_id', 'review_status', 'created_at'],
            'idx_ai_candidates_review',
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('ai_extraction_candidates');
        $schema->dropTable('ai_extractions');
        $schema->dropTable('ai_provider_credentials');
        $schema->dropTable('ai_settings');
    }

    private function homeId(Table $table): void
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
