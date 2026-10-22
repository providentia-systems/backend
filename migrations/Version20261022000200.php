<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve immutable receipt purchase-day corrections and their authorship';
    }

    public function up(Schema $schema): void
    {
        $corrections = $schema->createTable('receipt_date_corrections');
        foreach (['id', 'home_id', 'receipt_id', 'actor_user_id'] as $column) {
            $corrections->addColumn($column, Types::STRING, ['length' => 36]);
        }
        foreach (['original_purchase_date', 'purchase_date'] as $column) {
            $corrections->addColumn($column, Types::STRING, ['length' => 10]);
        }
        $corrections->addColumn('reason', Types::STRING, ['length' => 500]);
        $corrections->addColumn('receipt_revision', Types::INTEGER);
        $corrections->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $corrections->setPrimaryKey(['id']);
        $corrections->addUniqueIndex(['home_id', 'receipt_id', 'receipt_revision'], 'uniq_receipt_date_revision');
        $corrections->addIndex(['home_id', 'receipt_id'], 'idx_receipt_date_history');
        $corrections->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM receipt_date_corrections') > 0,
            'Receipt date correction history cannot be discarded by a downgrade.',
        );
        $schema->dropTable('receipt_date_corrections');
    }
}
