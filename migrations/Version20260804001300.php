<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804001300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consent-bound catalog contributions and asynchronous data-governance requests';
    }

    public function up(Schema $schema): void
    {
        $consents = $schema->createTable('catalog_contribution_consents');
        $consents->addColumn('home_id', Types::STRING, ['length' => 36]);
        $consents->addColumn('share_product_identity', Types::BOOLEAN, ['default' => false]);
        $consents->addColumn('share_product_images', Types::BOOLEAN, ['default' => false]);
        $consents->addColumn('share_store_prices', Types::BOOLEAN, ['default' => false]);
        $consents->addColumn('notice_version', Types::STRING, ['length' => 32]);
        $consents->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $consents->addColumn('updated_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $consents->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $consents->setPrimaryKey(['home_id']);
        $consents->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $consents->addForeignKeyConstraint('users', ['updated_by_user_id'], ['id'], ['onDelete' => 'SET NULL']);

        $receipts = $schema->createTable('catalog_consent_receipts');
        $receipts->addColumn('id', Types::STRING, ['length' => 36]);
        $receipts->addColumn('home_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $receipts->addColumn('consent_revision', Types::INTEGER);
        $receipts->addColumn('share_product_identity', Types::BOOLEAN);
        $receipts->addColumn('share_product_images', Types::BOOLEAN);
        $receipts->addColumn('share_store_prices', Types::BOOLEAN);
        $receipts->addColumn('notice_version', Types::STRING, ['length' => 32]);
        $receipts->addColumn('recorded_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $receipts->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);
        $receipts->setPrimaryKey(['id']);
        $receipts->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'SET NULL']);
        $receipts->addForeignKeyConstraint('users', ['recorded_by_user_id'], ['id'], ['onDelete' => 'SET NULL']);
        $receipts->addUniqueIndex(['home_id', 'consent_revision'], 'uniq_catalog_consent_revision');

        $contributions = $schema->createTable('catalog_contributions');
        $contributions->addColumn('id', Types::STRING, ['length' => 36]);
        $contributions->addColumn('home_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $contributions->addColumn('consent_receipt_id', Types::STRING, ['length' => 36]);
        $contributions->addColumn('contribution_type', Types::STRING, ['length' => 32]);
        $contributions->addColumn('source_fingerprint', Types::STRING, ['length' => 64, 'notnull' => false]);
        $contributions->addColumn('payload_json', Types::TEXT);
        $contributions->addColumn('moderation_status', Types::STRING, ['length' => 24]);
        $contributions->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $contributions->addColumn('submitted_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $contributions->addColumn('reviewed_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $contributions->addColumn('review_reason', Types::STRING, ['length' => 500, 'notnull' => false]);
        $contributions->addColumn('reviewed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $contributions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $contributions->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $contributions->setPrimaryKey(['id']);
        $contributions->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'SET NULL']);
        $contributions->addForeignKeyConstraint('catalog_consent_receipts', ['consent_receipt_id'], ['id']);
        $contributions->addForeignKeyConstraint('users', ['submitted_by_user_id'], ['id'], ['onDelete' => 'SET NULL']);
        $contributions->addForeignKeyConstraint('users', ['reviewed_by_user_id'], ['id'], ['onDelete' => 'SET NULL']);
        $contributions->addIndex(
            ['moderation_status', 'contribution_type', 'created_at'],
            'idx_catalog_contribution_review',
        );
        $contributions->addIndex(['home_id', 'created_at'], 'idx_catalog_contribution_home');

        $requests = $schema->createTable('data_governance_requests');
        $requests->addColumn('id', Types::STRING, ['length' => 36]);
        $requests->addColumn('request_kind', Types::STRING, ['length' => 32]);
        $requests->addColumn('scope_type', Types::STRING, ['length' => 16]);
        $requests->addColumn('scope_fingerprint', Types::STRING, ['length' => 64]);
        $requests->addColumn('subject_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $requests->addColumn('home_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $requests->addColumn('requested_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $requests->addColumn('status', Types::STRING, ['length' => 24]);
        $requests->addColumn('active_key', Types::STRING, ['length' => 8, 'notnull' => false]);
        $requests->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $requests->addColumn('retained_data_disclosure_json', Types::TEXT);
        $requests->addColumn('artifact_reference', Types::STRING, ['length' => 191, 'notnull' => false]);
        $requests->addColumn('artifact_nonce', Types::STRING, ['length' => 64, 'notnull' => false]);
        $requests->addColumn('artifact_sha256', Types::STRING, ['length' => 64, 'notnull' => false]);
        $requests->addColumn('artifact_size', Types::BIGINT, ['notnull' => false]);
        $requests->addColumn('artifact_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('download_token_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
        $requests->addColumn('download_token_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('downloaded_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('failure_reason', Types::STRING, ['length' => 500, 'notnull' => false]);
        $requests->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('cancelled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $requests->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $requests->setPrimaryKey(['id']);
        $requests->addForeignKeyConstraint('users', ['subject_user_id'], ['id'], ['onDelete' => 'SET NULL']);
        $requests->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'SET NULL']);
        $requests->addForeignKeyConstraint('users', ['requested_by_user_id'], ['id'], ['onDelete' => 'SET NULL']);
        $requests->addUniqueIndex(
            ['scope_fingerprint', 'request_kind', 'active_key'],
            'uniq_data_governance_active_request',
        );
        $requests->addIndex(['subject_user_id', 'created_at'], 'idx_data_governance_subject');
        $requests->addIndex(['home_id', 'created_at'], 'idx_data_governance_home');
        $requests->addIndex(['status', 'created_at'], 'idx_data_governance_work');
        $requests->addUniqueIndex(['download_token_hash'], 'uniq_data_governance_download_token');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('data_governance_requests');
        $schema->dropTable('catalog_contributions');
        $schema->dropTable('catalog_consent_receipts');
        $schema->dropTable('catalog_contribution_consents');
    }
}
