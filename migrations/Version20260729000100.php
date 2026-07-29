<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portable Phase 1 Doctrine persistence and transactional outbox proof';
    }

    public function up(Schema $schema): void
    {
        $foundation = $schema->createTable('foundation_records');
        $foundation->addColumn('id', Types::STRING, ['length' => 36]);
        $foundation->addColumn('label', Types::STRING, ['length' => 191]);
        $foundation->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $foundation->setPrimaryKey(['id']);
        $foundation->addIndex(['created_at'], 'idx_foundation_created');

        $outbox = $schema->createTable('outbox_messages');
        $outbox->addColumn('id', Types::STRING, ['length' => 36]);
        $outbox->addColumn('message_type', Types::STRING, ['length' => 191]);
        $outbox->addColumn('queue_name', Types::STRING, ['length' => 191]);
        $outbox->addColumn('payload', Types::TEXT);
        $outbox->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $outbox->addColumn('available_at', Types::DATETIME_IMMUTABLE);
        $outbox->addColumn('published_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $outbox->addColumn('attempts', Types::INTEGER, ['default' => 0, 'unsigned' => true]);
        $outbox->addColumn('last_error', Types::TEXT, ['notnull' => false]);
        $outbox->addColumn('status', Types::STRING, ['length' => 16]);
        $outbox->setPrimaryKey(['id']);
        $outbox->addIndex(['status', 'available_at', 'occurred_at'], 'idx_outbox_dispatch');
        $outbox->addIndex(['published_at'], 'idx_outbox_published');

        $failed = $schema->createTable('async_failed_messages');
        $failed->addColumn('id', Types::STRING, ['length' => 36]);
        $failed->addColumn('source_message_id', Types::STRING, ['length' => 36]);
        $failed->addColumn('failed_at', Types::DATETIME_IMMUTABLE);
        $failed->addColumn('reason', Types::TEXT);
        $failed->addColumn('resolved_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $failed->setPrimaryKey(['id']);
        $failed->addIndex(['resolved_at', 'failed_at'], 'idx_failed_review');

        $processed = $schema->createTable('async_processed_messages');
        $processed->addColumn('message_id', Types::STRING, ['length' => 64]);
        $processed->addColumn('processed_at', Types::DATETIME_IMMUTABLE);
        $processed->addColumn('handler_name', Types::STRING, ['length' => 191]);
        $processed->setPrimaryKey(['message_id']);
        $processed->addIndex(['processed_at'], 'idx_async_processed');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('async_processed_messages');
        $schema->dropTable('async_failed_messages');
        $schema->dropTable('outbox_messages');
        $schema->dropTable('foundation_records');
    }
}
