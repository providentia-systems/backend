<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804001100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Protocol-v2 synchronization paging, operation recovery and retention primitives';
    }

    public function up(Schema $schema): void
    {
        $changes = $schema->getTable('change_log');
        $changes->addIndex(
            ['home_id', 'entity_type', 'entity_id', 'sequence_id'],
            'idx_change_log_snapshot_page',
        );

        $tombstones = $schema->getTable('record_tombstones');
        $tombstones->addIndex(
            ['home_id', 'retain_until', 'change_cursor'],
            'idx_tombstones_retention',
        );

        $retention = $schema->createTable('sync_retention_state');
        $retention->addColumn('home_id', Types::STRING, ['length' => 36]);
        $retention->addColumn('minimum_available_cursor', Types::BIGINT, ['default' => 0]);
        $retention->addColumn('compacted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $retention->setPrimaryKey(['home_id']);
        $retention->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('sync_retention_state');
        $schema->getTable('record_tombstones')->dropIndex('idx_tombstones_retention');
        $schema->getTable('change_log')->dropIndex('idx_change_log_snapshot_page');
    }
}
