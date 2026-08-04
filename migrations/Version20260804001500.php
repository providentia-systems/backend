<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804001500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Staged, revisioned and idempotent home catalog imports';
    }

    public function up(Schema $schema): void
    {
        $locks = $schema->createTable('catalog_import_home_locks');
        $locks->addColumn('home_id', Types::STRING, ['length' => 36]);
        $locks->addColumn('revision', Types::INTEGER, ['default' => 0]);
        $locks->setPrimaryKey(['home_id']);
        $locks->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);

        $batches = $schema->createTable('catalog_import_batches');
        $batches->addColumn('id', Types::STRING, ['length' => 36]);
        $batches->addColumn('home_id', Types::STRING, ['length' => 36]);
        $batches->addColumn('requested_by_user_id', Types::STRING, ['length' => 36]);
        $batches->addColumn('idempotency_key_hash', Types::STRING, ['length' => 64]);
        $batches->addColumn('content_hash', Types::STRING, ['length' => 64]);
        $batches->addColumn('status', Types::STRING, ['length' => 24]);
        $batches->addColumn('row_count', Types::INTEGER);
        $batches->addColumn('valid_count', Types::INTEGER);
        $batches->addColumn('error_count', Types::INTEGER);
        $batches->addColumn('imported_count', Types::INTEGER, ['default' => 0]);
        $batches->addColumn('skipped_count', Types::INTEGER, ['default' => 0]);
        $batches->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $batches->addColumn('confirmed_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $batches->addColumn('confirmed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $batches->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $batches->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $batches->setPrimaryKey(['id']);
        $batches->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $batches->addForeignKeyConstraint('users', ['requested_by_user_id'], ['id']);
        $batches->addForeignKeyConstraint('users', ['confirmed_by_user_id'], ['id']);
        $batches->addUniqueIndex(['home_id', 'idempotency_key_hash'], 'uniq_catalog_import_idempotency');
        $batches->addIndex(['home_id', 'status', 'created_at'], 'idx_catalog_import_home_status');

        $rows = $schema->createTable('catalog_import_rows');
        $rows->addColumn('batch_id', Types::STRING, ['length' => 36]);
        $rows->addColumn('position', Types::INTEGER);
        $rows->addColumn('record_type', Types::STRING, ['length' => 32]);
        $rows->addColumn('payload_json', Types::TEXT);
        $rows->addColumn('resolution', Types::STRING, ['length' => 32]);
        $rows->addColumn('target_home_product_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $rows->addColumn('matched_home_product_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $rows->addColumn('product_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $rows->addColumn('pack_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $rows->addColumn('private_name', Types::STRING, ['length' => 191, 'notnull' => false]);
        $rows->addColumn('normalized_private_name', Types::STRING, ['length' => 191, 'notnull' => false]);
        $rows->addColumn('original_pack_text', Types::STRING, ['length' => 191, 'notnull' => false]);
        $rows->addColumn('deduplication_key', Types::STRING, ['length' => 191, 'notnull' => false]);
        $rows->addColumn('error_code', Types::STRING, ['length' => 64, 'notnull' => false]);
        $rows->addColumn('error_detail', Types::STRING, ['length' => 500, 'notnull' => false]);
        $rows->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $rows->setPrimaryKey(['batch_id', 'position']);
        $rows->addForeignKeyConstraint('catalog_import_batches', ['batch_id'], ['id'], ['onDelete' => 'CASCADE']);
        $rows->addForeignKeyConstraint('home_products', ['matched_home_product_id'], ['id']);
        $rows->addForeignKeyConstraint('products', ['product_id'], ['id']);
        $rows->addForeignKeyConstraint('product_packs', ['pack_id'], ['id']);
        $rows->addIndex(['batch_id', 'resolution'], 'idx_catalog_import_rows_resolution');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('catalog_import_rows');
        $schema->dropTable('catalog_import_batches');
        $schema->dropTable('catalog_import_home_locks');
    }
}
