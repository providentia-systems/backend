<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 5 inventory, purchasing, shopping lists, dashboard and baseline migration';
    }

    public function up(Schema $schema): void
    {
        $this->inventory($schema);
        $this->purchasing($schema);
        $this->shopping($schema);
        $this->migrationEvidence($schema);
    }

    public function down(Schema $schema): void
    {
        foreach (
            [
                'baseline_import_quarantine',
                'baseline_import_mappings',
                'baseline_import_runs',
                'shopping_list_lines',
                'shopping_lists',
                'price_observations',
                'receipt_line_matches',
                'receipt_lines',
                'receipts',
                'stores',
                'stock_threshold_preferences',
                'inventory_balances',
                'stock_movements',
                'stock_count_lines',
                'stock_count_sessions',
                'home_products',
                'home_locations',
            ] as $table
        ) {
            $schema->dropTable($table);
        }
    }

    private function inventory(Schema $schema): void
    {
        $locations = $schema->createTable('home_locations');
        $this->homeId($locations);
        $locations->addColumn('name', Types::STRING, ['length' => 120]);
        $locations->addColumn('normalized_name', Types::STRING, ['length' => 120]);
        $locations->addColumn('kind', Types::STRING, ['length' => 32]);
        $locations->addColumn('status', Types::STRING, ['length' => 24]);
        $locations->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $this->timestamps($locations);
        $locations->addUniqueIndex(['home_id', 'normalized_name'], 'uniq_home_locations_name');
        $locations->addIndex(['home_id', 'status'], 'idx_home_locations_status');

        $homeProducts = $schema->createTable('home_products');
        $this->homeId($homeProducts);
        $homeProducts->addColumn('product_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $homeProducts->addColumn('pack_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $homeProducts->addColumn('private_name', Types::STRING, ['length' => 191, 'notnull' => false]);
        $homeProducts->addColumn('normalized_private_name', Types::STRING, [
            'length' => 191,
            'notnull' => false,
        ]);
        $homeProducts->addColumn('original_pack_text', Types::STRING, [
            'length' => 191,
            'notnull' => false,
        ]);
        $homeProducts->addColumn('status', Types::STRING, ['length' => 24]);
        $homeProducts->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $this->timestamps($homeProducts);
        $homeProducts->addForeignKeyConstraint('products', ['product_id'], ['id']);
        $homeProducts->addForeignKeyConstraint('product_packs', ['pack_id'], ['id']);
        $homeProducts->addIndex(['home_id', 'status'], 'idx_home_products_status');
        $homeProducts->addIndex(['home_id', 'product_id', 'pack_id'], 'idx_home_products_catalog');
        $homeProducts->addIndex(
            ['home_id', 'normalized_private_name'],
            'idx_home_products_private_name',
        );

        $sessions = $schema->createTable('stock_count_sessions');
        $this->homeId($sessions);
        $sessions->addColumn('location_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $sessions->addColumn('status', Types::STRING, ['length' => 24]);
        $sessions->addColumn('notes', Types::TEXT);
        $sessions->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $sessions->addColumn('opened_by_user_id', Types::STRING, ['length' => 36]);
        $sessions->addColumn('opened_at', Types::DATETIME_IMMUTABLE);
        $sessions->addColumn('closed_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $sessions->addColumn('closed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->timestamps($sessions);
        $sessions->addForeignKeyConstraint('home_locations', ['location_id'], ['id']);
        $sessions->addIndex(['home_id', 'status', 'opened_at'], 'idx_stock_sessions_home_state');

        $lines = $schema->createTable('stock_count_lines');
        $this->homeId($lines);
        $lines->addColumn('session_id', Types::STRING, ['length' => 36]);
        $lines->addColumn('home_product_id', Types::STRING, ['length' => 36]);
        $lines->addColumn('quantity', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $lines->addColumn('confidence', Types::DECIMAL, [
            'precision' => 5,
            'scale' => 4,
            'notnull' => false,
        ]);
        $lines->addColumn('source', Types::STRING, ['length' => 32]);
        $lines->addColumn('notes', Types::TEXT);
        $lines->addColumn('status', Types::STRING, ['length' => 24]);
        $lines->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $lines->addColumn('counted_by_user_id', Types::STRING, ['length' => 36]);
        $this->timestamps($lines);
        $lines->addForeignKeyConstraint('stock_count_sessions', ['session_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);
        $lines->addForeignKeyConstraint('home_products', ['home_product_id'], ['id']);
        $lines->addUniqueIndex(['session_id', 'home_product_id'], 'uniq_stock_count_product');
        $lines->addIndex(['home_id', 'session_id', 'status'], 'idx_stock_count_lines_state');

        $movements = $schema->createTable('stock_movements');
        $this->homeId($movements);
        $movements->addColumn('home_product_id', Types::STRING, ['length' => 36]);
        $movements->addColumn('movement_type', Types::STRING, ['length' => 32]);
        $movements->addColumn('quantity_delta', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $movements->addColumn('source_type', Types::STRING, ['length' => 40]);
        $movements->addColumn('source_id', Types::STRING, ['length' => 64]);
        $movements->addColumn('reason', Types::STRING, ['length' => 191]);
        $movements->addColumn('actor_user_id', Types::STRING, ['length' => 36]);
        $movements->addColumn('reversed_movement_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $movements->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $movements->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $movements->addForeignKeyConstraint('home_products', ['home_product_id'], ['id']);
        $movements->addForeignKeyConstraint('stock_movements', ['reversed_movement_id'], ['id']);
        $movements->addUniqueIndex(
            ['home_id', 'source_type', 'source_id', 'home_product_id'],
            'uniq_stock_movement_source',
        );
        $movements->addIndex(
            ['home_id', 'home_product_id', 'occurred_at'],
            'idx_stock_movements_product_time',
        );

        $balances = $schema->createTable('inventory_balances');
        $balances->addColumn('home_id', Types::STRING, ['length' => 36]);
        $balances->addColumn('home_product_id', Types::STRING, ['length' => 36]);
        $balances->addColumn('quantity', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $balances->addColumn('last_movement_id', Types::STRING, ['length' => 36]);
        $balances->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $balances->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $balances->setPrimaryKey(['home_id', 'home_product_id']);
        $balances->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $balances->addForeignKeyConstraint('home_products', ['home_product_id'], ['id']);
        $balances->addIndex(['home_id', 'quantity'], 'idx_inventory_balances_quantity');

        $preferences = $schema->createTable('stock_threshold_preferences');
        $preferences->addColumn('home_id', Types::STRING, ['length' => 36]);
        $preferences->addColumn('home_product_id', Types::STRING, ['length' => 36]);
        $preferences->addColumn('minimum_quantity', Types::DECIMAL, [
            'precision' => 20,
            'scale' => 8,
            'notnull' => false,
        ]);
        $preferences->addColumn('always_keep', Types::BOOLEAN, ['default' => false]);
        $preferences->addColumn('never_suggest', Types::BOOLEAN, ['default' => false]);
        $preferences->addColumn('preferred_pack_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $preferences->addColumn('lead_time_days', Types::INTEGER, ['default' => 0]);
        $preferences->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $preferences->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $preferences->setPrimaryKey(['home_id', 'home_product_id']);
        $preferences->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $preferences->addForeignKeyConstraint('home_products', ['home_product_id'], ['id']);
        $preferences->addForeignKeyConstraint('product_packs', ['preferred_pack_id'], ['id']);
    }

    private function purchasing(Schema $schema): void
    {
        $stores = $schema->createTable('stores');
        $this->homeId($stores);
        $stores->addColumn('name', Types::STRING, ['length' => 191]);
        $stores->addColumn('normalized_name', Types::STRING, ['length' => 191]);
        $stores->addColumn('location', Types::STRING, ['length' => 191]);
        $stores->addColumn('status', Types::STRING, ['length' => 24]);
        $stores->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $this->timestamps($stores);
        $stores->addUniqueIndex(['home_id', 'normalized_name', 'location'], 'uniq_stores_home_name');

        $receipts = $schema->createTable('receipts');
        $this->homeId($receipts);
        $receipts->addColumn('store_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $receipts->addColumn('purchase_date', Types::DATE_IMMUTABLE);
        $receipts->addColumn('currency', Types::STRING, ['length' => 3]);
        $receipts->addColumn('total_amount', Types::DECIMAL, [
            'precision' => 20,
            'scale' => 2,
            'notnull' => false,
        ]);
        $receipts->addColumn('status', Types::STRING, ['length' => 24]);
        $receipts->addColumn('source', Types::STRING, ['length' => 32]);
        $receipts->addColumn('source_reference', Types::STRING, [
            'length' => 191,
            'notnull' => false,
        ]);
        $receipts->addColumn('notes', Types::TEXT);
        $receipts->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $receipts->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $receipts->addColumn('committed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->timestamps($receipts);
        $receipts->addForeignKeyConstraint('stores', ['store_id'], ['id']);
        $receipts->addIndex(['home_id', 'purchase_date', 'status'], 'idx_receipts_home_date');
        $receipts->addUniqueIndex(
            ['home_id', 'source', 'source_reference'],
            'uniq_receipts_source_reference',
        );

        $lines = $schema->createTable('receipt_lines');
        $this->homeId($lines);
        $lines->addColumn('receipt_id', Types::STRING, ['length' => 36]);
        $lines->addColumn('line_number', Types::INTEGER);
        $lines->addColumn('raw_description', Types::STRING, ['length' => 500]);
        $lines->addColumn('quantity', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $lines->addColumn('original_pack_text', Types::STRING, [
            'length' => 191,
            'notnull' => false,
        ]);
        $lines->addColumn('unit_price', Types::DECIMAL, [
            'precision' => 20,
            'scale' => 2,
            'notnull' => false,
        ]);
        $lines->addColumn('line_total', Types::DECIMAL, [
            'precision' => 20,
            'scale' => 2,
            'notnull' => false,
        ]);
        $lines->addColumn('home_product_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $lines->addColumn('approval_status', Types::STRING, ['length' => 24]);
        $lines->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $this->timestamps($lines);
        $lines->addForeignKeyConstraint('receipts', ['receipt_id'], ['id'], ['onDelete' => 'CASCADE']);
        $lines->addForeignKeyConstraint('home_products', ['home_product_id'], ['id']);
        $lines->addUniqueIndex(['receipt_id', 'line_number'], 'uniq_receipt_line_number');
        $lines->addIndex(['home_id', 'approval_status'], 'idx_receipt_lines_review');

        $matches = $schema->createTable('receipt_line_matches');
        $this->homeId($matches);
        $matches->addColumn('receipt_line_id', Types::STRING, ['length' => 36]);
        $matches->addColumn('product_pack_id', Types::STRING, ['length' => 36]);
        $matches->addColumn('match_method', Types::STRING, ['length' => 40]);
        $matches->addColumn('confidence', Types::DECIMAL, ['precision' => 5, 'scale' => 4]);
        $matches->addColumn('status', Types::STRING, ['length' => 24]);
        $matches->addColumn('decided_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $matches->addColumn('decided_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $matches->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $matches->addForeignKeyConstraint('receipt_lines', ['receipt_line_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);
        $matches->addForeignKeyConstraint('product_packs', ['product_pack_id'], ['id']);
        $matches->addIndex(['home_id', 'receipt_line_id', 'status'], 'idx_receipt_matches_line');

        $prices = $schema->createTable('price_observations');
        $this->homeId($prices);
        $prices->addColumn('receipt_line_id', Types::STRING, ['length' => 36]);
        $prices->addColumn('product_pack_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $prices->addColumn('store_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $prices->addColumn('currency', Types::STRING, ['length' => 3]);
        $prices->addColumn('quantity', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $prices->addColumn('unit_price', Types::DECIMAL, [
            'precision' => 20,
            'scale' => 2,
            'notnull' => false,
        ]);
        $prices->addColumn('line_total', Types::DECIMAL, ['precision' => 20, 'scale' => 2]);
        $prices->addColumn('observed_at', Types::DATETIME_IMMUTABLE);
        $prices->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $prices->addForeignKeyConstraint('receipt_lines', ['receipt_line_id'], ['id']);
        $prices->addForeignKeyConstraint('product_packs', ['product_pack_id'], ['id']);
        $prices->addForeignKeyConstraint('stores', ['store_id'], ['id']);
        $prices->addUniqueIndex(['receipt_line_id'], 'uniq_price_observation_receipt_line');
        $prices->addIndex(
            ['home_id', 'product_pack_id', 'observed_at'],
            'idx_price_observation_pack_time',
        );
    }

    private function shopping(Schema $schema): void
    {
        $lists = $schema->createTable('shopping_lists');
        $this->homeId($lists);
        $lists->addColumn('name', Types::STRING, ['length' => 120]);
        $lists->addColumn('kind', Types::STRING, ['length' => 24]);
        $lists->addColumn('status', Types::STRING, ['length' => 24]);
        $lists->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $lists->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $this->timestamps($lists);
        $lists->addIndex(['home_id', 'status', 'updated_at'], 'idx_shopping_lists_home');

        $lines = $schema->createTable('shopping_list_lines');
        $this->homeId($lines);
        $lines->addColumn('shopping_list_id', Types::STRING, ['length' => 36]);
        $lines->addColumn('home_product_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $lines->addColumn('description', Types::STRING, ['length' => 191]);
        $lines->addColumn('source', Types::STRING, ['length' => 24]);
        $lines->addColumn('quantity_to_buy', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $lines->addColumn('explanation', Types::TEXT);
        $lines->addColumn('confidence', Types::DECIMAL, [
            'precision' => 5,
            'scale' => 4,
            'notnull' => false,
        ]);
        $lines->addColumn('checked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $lines->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $this->timestamps($lines);
        $lines->addForeignKeyConstraint('shopping_lists', ['shopping_list_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);
        $lines->addForeignKeyConstraint('home_products', ['home_product_id'], ['id']);
        $lines->addIndex(
            ['home_id', 'shopping_list_id', 'checked_at'],
            'idx_shopping_list_lines_state',
        );
    }

    private function migrationEvidence(Schema $schema): void
    {
        $runs = $schema->createTable('baseline_import_runs');
        $this->homeId($runs);
        $runs->addColumn('source_commit', Types::STRING, ['length' => 40]);
        $runs->addColumn('archive_sha256', Types::STRING, ['length' => 64]);
        $runs->addColumn('mode', Types::STRING, ['length' => 16]);
        $runs->addColumn('status', Types::STRING, ['length' => 24]);
        $runs->addColumn('reconciliation_json', Types::TEXT);
        $runs->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $runs->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $runs->addUniqueIndex(
            ['home_id', 'source_commit', 'archive_sha256', 'mode'],
            'uniq_baseline_import_identity',
        );

        $mappings = $schema->createTable('baseline_import_mappings');
        $mappings->addColumn('import_run_id', Types::STRING, ['length' => 36]);
        $mappings->addColumn('source_type', Types::STRING, ['length' => 40]);
        $mappings->addColumn('source_id', Types::STRING, ['length' => 191]);
        $mappings->addColumn('destination_type', Types::STRING, ['length' => 40]);
        $mappings->addColumn('destination_id', Types::STRING, ['length' => 36]);
        $mappings->addColumn('source_digest', Types::STRING, ['length' => 64]);
        $mappings->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $mappings->setPrimaryKey(['import_run_id', 'source_type', 'source_id']);
        $mappings->addForeignKeyConstraint('baseline_import_runs', ['import_run_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);

        $quarantine = $schema->createTable('baseline_import_quarantine');
        $this->homeId($quarantine);
        $quarantine->addColumn('import_run_id', Types::STRING, ['length' => 36]);
        $quarantine->addColumn('source_type', Types::STRING, ['length' => 40]);
        $quarantine->addColumn('source_id', Types::STRING, ['length' => 191]);
        $quarantine->addColumn('raw_payload_json', Types::TEXT);
        $quarantine->addColumn('reason', Types::STRING, ['length' => 191]);
        $quarantine->addColumn('resolution_status', Types::STRING, ['length' => 24]);
        $quarantine->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $quarantine->addForeignKeyConstraint('baseline_import_runs', ['import_run_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);
        $quarantine->addUniqueIndex(
            ['import_run_id', 'source_type', 'source_id'],
            'uniq_baseline_import_quarantine_row',
        );
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
