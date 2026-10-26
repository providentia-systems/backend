<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit atomic completion of omitted and unresolved finished-receipt purchases';
    }

    public function up(Schema $schema): void
    {
        $corrections = $schema->createTable('receipt_line_corrections');
        foreach (['id', 'home_id', 'receipt_id', 'receipt_line_id', 'actor_user_id'] as $column) {
            $corrections->addColumn($column, Types::STRING, ['length' => 36]);
        }
        $corrections->addColumn('request_hash', Types::STRING, ['length' => 64]);
        $corrections->addColumn('reason', Types::STRING, ['length' => 500]);
        $corrections->addColumn('receipt_revision', Types::INTEGER);
        $corrections->addColumn('original_line_json', Types::TEXT, ['notnull' => false]);
        $corrections->addColumn('next_line_json', Types::TEXT);
        $corrections->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $corrections->setPrimaryKey(['id']);
        $corrections->addUniqueIndex(['home_id', 'receipt_id', 'receipt_revision'], 'uniq_receipt_line_revision');
        $corrections->addIndex(['home_id', 'receipt_id'], 'idx_receipt_line_correction_history');
        $corrections->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM receipt_line_corrections') > 0,
            'Receipt-line correction history cannot be discarded by a downgrade.',
        );
        $schema->dropTable('receipt_line_corrections');
    }
}
