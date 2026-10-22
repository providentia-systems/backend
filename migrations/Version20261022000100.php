<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve physical observation times, audited corrections and chronological balance projections';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('stock_count_sessions')->addColumn(
            'observed_at',
            Types::DATETIME_IMMUTABLE,
            ['notnull' => false],
        );
        $schema->getTable('stock_count_sessions')->addColumn(
            'observed_timezone',
            Types::STRING,
            ['length' => 64, 'notnull' => false],
        );
        $schema->getTable('stock_count_lines')->addColumn(
            'stock_unit',
            Types::STRING,
            ['length' => 16, 'notnull' => false],
        );
        $movements = $schema->getTable('stock_movements');
        $movements->addColumn('receipt_line_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $movements->addColumn('stock_unit', Types::STRING, ['length' => 16, 'notnull' => false]);
        $movements->addIndex(['home_id', 'receipt_line_id'], 'idx_stock_movement_receipt_line');
        $balances = $schema->getTable('inventory_balances');
        $balances->getColumn('last_movement_id')->setNotnull(false);
        $balances->addColumn('uncertain', Types::BOOLEAN, ['default' => false]);
        $balances->addColumn(
            'pending_quantity',
            $this->platform instanceof SQLitePlatform ? Types::STRING : Types::DECIMAL,
            ['length' => 32, 'precision' => 20, 'scale' => 8, 'default' => '0'],
        );
        $balances->addColumn('limitations_json', Types::TEXT, ['notnull' => false]);
        $balances->addColumn('checkpoint_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $dates = $schema->createTable('stock_purchase_date_corrections');
        $dates->addColumn('id', Types::STRING, ['length' => 36]);
        $dates->addColumn('home_id', Types::STRING, ['length' => 36]);
        $dates->addColumn('receipt_line_id', Types::STRING, ['length' => 36]);
        $dates->addColumn('original_purchase_date', Types::DATE_IMMUTABLE);
        $dates->addColumn('purchase_date', Types::DATE_IMMUTABLE);
        $dates->addColumn('actor_user_id', Types::STRING, ['length' => 36]);
        $dates->addColumn('reason', Types::STRING, ['length' => 500]);
        $dates->addColumn('revision', Types::INTEGER);
        $dates->addUniqueIndex(['home_id', 'receipt_line_id', 'revision'], 'uniq_stock_purchase_date_revision');
        $dates->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $dates->setPrimaryKey(['id']);
        $dates->addIndex(['home_id', 'receipt_line_id', 'created_at'], 'idx_stock_purchase_date_history');
        $dates->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $baselines = $schema->createTable('stock_projection_baselines');
        $baselines->addColumn('home_id', Types::STRING, ['length' => 36]);
        $baselines->addColumn('home_product_id', Types::STRING, ['length' => 36]);
        $baselines->addColumn('quantity', Types::STRING, ['length' => 32]);
        $baselines->addColumn('unit', Types::STRING, ['length' => 16]);
        $baselines->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);
        $baselines->addColumn('last_movement_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $baselines->addColumn('included_movement_ids_json', Types::TEXT);
        $baselines->setPrimaryKey(['home_id', 'home_product_id']);
        $baselines->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $baselines->addForeignKeyConstraint('home_products', ['home_product_id'], ['id'], ['onDelete' => 'CASCADE']);
        $reviews = $schema->createTable('stock_count_reviews');
        $reviews->addColumn('id', Types::STRING, ['length' => 36]);
        $reviews->addColumn('home_id', Types::STRING, ['length' => 36]);
        $reviews->addColumn('session_id', Types::STRING, ['length' => 36]);
        $reviews->addColumn('actor_user_id', Types::STRING, ['length' => 36]);
        $reviews->addColumn('previous_snapshot_json', Types::TEXT);
        $reviews->addColumn('next_snapshot_json', Types::TEXT);
        $reviews->addColumn('reason', Types::STRING, ['length' => 191]);
        $reviews->addColumn('revision', Types::INTEGER);
        $reviews->addUniqueIndex(['home_id', 'session_id', 'revision'], 'uniq_stock_count_review_revision');
        $reviews->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $reviews->setPrimaryKey(['id']);
        $reviews->addIndex(['home_id', 'session_id', 'created_at'], 'idx_stock_count_review_history');
        $reviews->addForeignKeyConstraint('stock_count_sessions', ['session_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function postUp(Schema $schema): void
    {
        $prefix = $this->platform->getSubstringExpression('source_id', '1', '36');
        $this->connection->executeStatement(
            'UPDATE stock_movements SET receipt_line_id = ' . $prefix . '
             WHERE source_type IN (:purchase, :correction)',
            ['purchase' => 'receipt-line', 'correction' => 'receipt-line-correction'],
        );
        $this->connection->executeStatement(
            'UPDATE stock_movements SET stock_unit = COALESCE(
                (SELECT rl.stock_unit FROM receipt_lines rl WHERE rl.home_id = stock_movements.home_id
                 AND rl.id = stock_movements.receipt_line_id),
                (SELECT hp.unit FROM home_products hp
                 WHERE hp.home_id = stock_movements.home_id AND hp.id = stock_movements.home_product_id))',
        );
        $at = gmdate('Y-m-d H:i:s');
        foreach (
            $this->connection->fetchAllAssociative(
                'SELECT ib.home_id, ib.home_product_id, ib.quantity, ib.last_movement_id, hp.unit
             FROM inventory_balances ib INNER JOIN home_products hp
               ON hp.home_id = ib.home_id AND hp.id = ib.home_product_id
             WHERE EXISTS (SELECT 1 FROM stock_movements sm WHERE sm.home_id = ib.home_id
                AND sm.home_product_id = ib.home_product_id AND sm.movement_type = :count_type)',
                ['count_type' => 'count-reconciliation'],
            ) as $row
        ) {
            $ids = $this->connection->fetchFirstColumn(
                'SELECT id FROM stock_movements WHERE home_id = :home AND home_product_id = :product',
                ['home' => $row['home_id'], 'product' => $row['home_product_id']],
            );
            $this->connection->insert('stock_projection_baselines', [
                'home_id' => $row['home_id'], 'home_product_id' => $row['home_product_id'],
                'quantity' => (string) $row['quantity'], 'unit' => $row['unit'],
                'recorded_at' => $at, 'last_movement_id' => $row['last_movement_id'],
                'included_movement_ids_json' => json_encode($ids, JSON_THROW_ON_ERROR),
            ]);
            $this->connection->update('inventory_balances', [
                'uncertain' => true,
                'limitations_json' => json_encode([
                    'A migrated stock projection is preserved until the household reviews '
                        . 'a complete reliable observation.',
                ], JSON_THROW_ON_ERROR),
            ], ['home_id' => $row['home_id'], 'home_product_id' => $row['home_product_id']]);
        }
        // Do not invent an observation time or certify historical count units/reliability.
        // A household can explicitly review an old session using the audited review API.
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_purchase_date_corrections') > 0
            || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_count_reviews') > 0
            || (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM stock_count_sessions WHERE observed_at IS NOT NULL',
            ) > 0
            || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM stock_projection_baselines') > 0,
            'Physical observation, correction or preserved projection evidence cannot be discarded by a downgrade.',
        );
        $schema->dropTable('stock_count_reviews');
        $schema->dropTable('stock_projection_baselines');
        $schema->dropTable('stock_purchase_date_corrections');
        $schema->getTable('stock_count_sessions')->dropColumn('observed_at');
        $schema->getTable('stock_count_sessions')->dropColumn('observed_timezone');
        $schema->getTable('stock_count_lines')->dropColumn('stock_unit');
        $movements = $schema->getTable('stock_movements');
        $movements->dropIndex('idx_stock_movement_receipt_line');
        $movements->dropColumn('receipt_line_id');
        $movements->dropColumn('stock_unit');
        foreach (['uncertain', 'pending_quantity', 'limitations_json', 'checkpoint_id'] as $column) {
            $schema->getTable('inventory_balances')->dropColumn($column);
        }
    }
}
