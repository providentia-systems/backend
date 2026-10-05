<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005000100 extends AbstractMigration
{
    /** @var array<string, array<string, array{int, int}>> */
    private const COLUMNS = [
        'units' => ['base_factor' => [20, 8]],
        'product_packs' => ['amount' => [20, 8], 'normalized_base_amount' => [20, 8]],
        'stock_count_lines' => ['quantity' => [20, 8], 'confidence' => [5, 4]],
        'stock_movements' => ['quantity_delta' => [20, 8]],
        'inventory_balances' => ['quantity' => [20, 8]],
        'stock_threshold_preferences' => ['minimum_quantity' => [20, 8]],
        'receipts' => ['total_amount' => [20, 2]],
        'receipt_lines' => ['quantity' => [20, 8], 'unit_price' => [20, 2], 'line_total' => [20, 2]],
        'receipt_line_matches' => ['confidence' => [5, 4]],
        'price_observations' => ['quantity' => [20, 8], 'unit_price' => [20, 2], 'line_total' => [20, 2]],
        'shopping_list_lines' => ['quantity_to_buy' => [20, 8], 'confidence' => [5, 4]],
        'ai_extraction_candidates' => ['confidence' => [5, 4]],
        'consumption_estimates' => ['daily_rate' => [20, 8], 'variability' => [20, 8], 'confidence_score' => [5, 4]],
        'shopping_suggestions' => [
            'expected_demand' => [20, 8], 'safety_stock' => [20, 8], 'factual_stock' => [20, 8],
            'usable_stock' => [20, 8], 'required_quantity' => [20, 8], 'confidence_score' => [5, 4],
        ],
        'suggestion_pack_options' => ['effective_total' => [20, 2], 'excess_quantity' => [20, 8]],
        'stock_preference_revisions' => ['minimum_quantity' => [20, 8]],
        'user_suggestion_feedback' => ['original_quantity' => [20, 8], 'result_quantity' => [20, 8]],
        'suggestion_backtest_results' => ['suggested_quantity' => [20, 8]],
    ];

    public function getDescription(): string
    {
        return 'Preserve exact decimal strings on SQLite; leave MySQL and MariaDB DECIMAL unchanged';
    }

    public function up(Schema $schema): void
    {
        $this->convert(true);
    }

    public function down(Schema $schema): void
    {
        $this->convert(false);
    }

    public function postUp(Schema $schema): void
    {
        $this->checkForeignKeys();
    }

    public function postDown(Schema $schema): void
    {
        $this->checkForeignKeys();
    }

    private function convert(bool $toText): void
    {
        if (! $this->platform instanceof SQLitePlatform) {
            return;
        }
        // PRAGMA foreign_keys cannot be changed inside Doctrine's transaction.
        // Dropping a referenced table with it enabled can cascade-delete data.
        $this->abortIf(
            (int) $this->connection->fetchOne('PRAGMA foreign_keys') !== 0,
            'SQLite decimal migration requires foreign_keys=OFF before starting the migration transaction. '
                . 'Back up the database, stop writers, and run with a dedicated migration connection.',
        );
        $this->checkForeignKeys();
        foreach (self::COLUMNS as $table => $columns) {
            $quotedTable = $this->connection->quoteIdentifier($table);
            $definition = (string) $this->connection->fetchOne(
                "SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = ?",
                [$table],
            );
            $this->abortIf($definition === '', 'Missing decimal table: ' . $table);
            foreach ($columns as $column => [$precision, $scale]) {
                $quotedColumn = $this->connection->quoteIdentifier($column);
                if (! $toText) {
                    $numeric = $this->plainNumber("CAST($quotedColumn AS NUMERIC)", $scale);
                    foreach (
                        $this->connection->iterateAssociative(
                            "SELECT DISTINCT $quotedColumn AS original, $numeric AS converted FROM $quotedTable "
                            . "WHERE $quotedColumn IS NOT NULL AND typeof($quotedColumn) <> 'blob'",
                        ) as $value
                    ) {
                        $original = (string) $value['original'];
                        // NUMERIC affinity leaves nonnumeric legacy confidence bands
                        // (for example "medium") as TEXT. CAST alone would turn them into 0.
                        if (preg_match('/^[+-]?(?:\\d+\\.?\\d*|\\.\\d+)(?:[eE][+-]?\\d+)?$/', trim($original)) !== 1) {
                            continue;
                        }
                        $canonical = str_contains($original, '.') ? rtrim(rtrim($original, '0'), '.') : $original;
                        $this->abortIf(
                            $canonical !== (string) $value['converted'],
                            'Refusing lossy SQLite decimal downgrade: ' . $table . '.' . $column
                                . '. Export exact values and keep TEXT storage or restore a pre-migration backup.',
                        );
                    }
                }
                $type = $toText ? '(?:NUMERIC|DECIMAL)\s*\(\s*\d+\s*,\s*\d+\s*\)' : 'TEXT';
                $pattern = '/([,(]\s*(?:"' . $column . '"|`' . $column . '`|'
                    . $column . ')\s+)' . $type . '/i';
                // The declaration is changed in place; constraints, defaults and
                // foreign keys are kept, including metadata DBAL does not introspect.
                $definition = (string) preg_replace(
                    $pattern,
                    '${1}' . ($toText ? 'TEXT' : "NUMERIC($precision, $scale)"),
                    $definition,
                    -1,
                    $count,
                );
                $this->abortIf($count !== 1, 'Unexpected decimal declaration: ' . $table . '.' . $column);
            }
            $allColumns = $this->connection->fetchAllAssociative('PRAGMA table_info(' . $quotedTable . ')');
            $names = [];
            $values = [];
            foreach ($allColumns as $metadata) {
                $name = (string) $metadata['name'];
                $quoted = $this->connection->quoteIdentifier($name);
                $names[] = $quoted;
                $values[] = $toText && isset($columns[$name])
                    ? $this->plainNumber($quoted, $columns[$name][1])
                    : $quoted;
            }
            $objects = $this->connection->fetchFirstColumn(
                "SELECT sql FROM sqlite_schema WHERE tbl_name = ? AND type IN ('index', 'trigger') "
                    . 'AND sql IS NOT NULL ORDER BY type, name',
                [$table],
            );
            $temporary = $this->connection->quoteIdentifier('__decimal_backup_' . $table);
            $this->addSql("CREATE TEMPORARY TABLE $temporary AS SELECT * FROM $quotedTable");
            $this->addSql("DROP TABLE $quotedTable");
            $this->addSql($definition);
            $this->addSql('INSERT INTO ' . $quotedTable . ' (' . implode(', ', $names) . ') SELECT '
                . implode(', ', $values) . ' FROM ' . $temporary);
            $this->addSql("DROP TABLE $temporary");
            foreach ($objects as $sql) {
                $this->addSql((string) $sql);
            }
        }
    }

    private function plainNumber(string $expression, int $scale): string
    {
        // Integer storage is already exact. REAL storage is normalized at its
        // declared scale without exponent notation. Precision lost by historical
        // NUMERIC affinity cannot be recovered by this migration.
        return "CASE WHEN $expression IS NULL THEN NULL "
            . "WHEN typeof($expression) = 'integer' THEN CAST($expression AS TEXT) "
            . "WHEN typeof($expression) IN ('text', 'blob') THEN $expression "
            . "ELSE rtrim(rtrim(printf('%.$scale" . "f', $expression), '0'), '.') END";
    }

    private function checkForeignKeys(): void
    {
        if ($this->platform instanceof SQLitePlatform) {
            $this->abortIf(
                $this->connection->fetchAssociative('PRAGMA foreign_key_check') !== false,
                'SQLite foreign-key violations prevent a safe decimal migration.',
            );
        }
    }
}
