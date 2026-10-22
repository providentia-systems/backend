<?php

declare(strict_types=1);

namespace ProvidentiaTest\Support;

use Doctrine\DBAL\Connection;

/** Adds the chronology read model to deliberately minimal SQLite test fixtures. */
final class InventoryChronologySchema
{
    public static function install(Connection $connection): void
    {
        foreach (
            [
            "CREATE TABLE IF NOT EXISTS homes (id TEXT PRIMARY KEY, default_timezone TEXT DEFAULT 'UTC')",
            'CREATE TABLE IF NOT EXISTS stock_count_sessions (
                id TEXT PRIMARY KEY, home_id TEXT, location_id TEXT, status TEXT, notes TEXT,
                scope_complete INTEGER DEFAULT 0, reliability TEXT DEFAULT \'unassessed\',
                observed_at TEXT, observed_timezone TEXT, opened_at TEXT, closed_at TEXT,
                created_at TEXT, updated_at TEXT,
                opened_by_user_id TEXT, closed_by_user_id TEXT, revision INTEGER DEFAULT 1
            )',
            'CREATE TABLE IF NOT EXISTS stock_count_lines (
                id TEXT PRIMARY KEY, home_id TEXT, session_id TEXT, home_product_id TEXT,
                quantity TEXT, stock_unit TEXT, confidence TEXT, source TEXT, notes TEXT,
                status TEXT DEFAULT \'confirmed\', revision INTEGER DEFAULT 1,
                counted_by_user_id TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE IF NOT EXISTS stock_purchase_date_corrections (
                id TEXT PRIMARY KEY, home_id TEXT, receipt_line_id TEXT,
                original_purchase_date TEXT, purchase_date TEXT, actor_user_id TEXT,
                reason TEXT, created_at TEXT, revision INTEGER NOT NULL DEFAULT 1
            )',
            'CREATE TABLE IF NOT EXISTS stock_count_reviews (
                id TEXT PRIMARY KEY, home_id TEXT, session_id TEXT, actor_user_id TEXT,
                previous_snapshot_json TEXT, next_snapshot_json TEXT, reason TEXT, created_at TEXT,
                revision INTEGER NOT NULL DEFAULT 1
            )',
            'CREATE TABLE IF NOT EXISTS stock_projection_baselines (
                home_id TEXT, home_product_id TEXT, quantity TEXT, unit TEXT, recorded_at TEXT,
                last_movement_id TEXT, included_movement_ids_json TEXT,
                PRIMARY KEY(home_id, home_product_id)
            )',
            ] as $statement
        ) {
            $connection->executeStatement($statement);
        }
        $columns = [
            'stock_count_sessions' => [
                'observed_at' => 'TEXT', 'observed_timezone' => 'TEXT', 'scope_complete' => 'INTEGER DEFAULT 0',
                'reliability' => "TEXT DEFAULT 'unassessed'", 'location_id' => 'TEXT',
                'closed_at' => 'TEXT', 'updated_at' => 'TEXT',
            ],
            'stock_count_lines' => ['stock_unit' => 'TEXT', 'updated_at' => 'TEXT'],
            'stock_movements' => [
                'stock_unit' => 'TEXT', 'receipt_line_id' => 'TEXT', 'home_id' => 'TEXT',
                'home_product_id' => 'TEXT', 'movement_type' => 'TEXT', 'quantity_delta' => 'TEXT',
                'source_type' => 'TEXT', 'source_id' => 'TEXT', 'occurred_at' => 'TEXT', 'created_at' => 'TEXT',
            ],
            'homes' => ['default_timezone' => "TEXT DEFAULT 'UTC'"],
            'stock_purchase_date_corrections' => ['revision' => 'INTEGER NOT NULL DEFAULT 1'],
            'stock_count_reviews' => ['revision' => 'INTEGER NOT NULL DEFAULT 1'],
            'inventory_balances' => [
                'uncertain' => 'INTEGER DEFAULT 0', 'pending_quantity' => "TEXT DEFAULT '0'",
                'limitations_json' => 'TEXT', 'checkpoint_id' => 'TEXT',
            ],
        ];
        $manager = $connection->createSchemaManager();
        foreach ($columns as $table => $definitions) {
            if (!$manager->tablesExist([$table])) {
                continue;
            }
            $existing = $manager->listTableColumns($table);
            foreach ($definitions as $column => $definition) {
                if (!isset($existing[$column])) {
                    $connection->executeStatement(
                        'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition,
                    );
                }
            }
        }
    }
}
