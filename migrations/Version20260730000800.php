<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8 deterministic consumption, suggestions, feedback, reporting and backtesting';
    }

    public function up(Schema $schema): void
    {
        $counts = $schema->getTable('stock_count_sessions');
        $counts->addColumn('scope_complete', Types::BOOLEAN, ['default' => false]);
        $counts->addColumn('reliability', Types::STRING, [
            'length' => 24,
            'default' => 'unassessed',
        ]);

        $preferences = $schema->getTable('stock_threshold_preferences');
        $preferences->addColumn('target_coverage_days', Types::INTEGER, [
            'notnull' => false,
        ]);
        $preferences->addColumn('snooze_until', Types::DATE_IMMUTABLE, [
            'notnull' => false,
        ]);

        $estimateRuns = $schema->createTable('consumption_estimate_runs');
        $this->homeId($estimateRuns);
        $estimateRuns->addColumn('method_version', Types::STRING, ['length' => 64]);
        $estimateRuns->addColumn('as_of', Types::DATETIME_IMMUTABLE);
        $estimateRuns->addColumn('input_watermark', Types::STRING, ['length' => 64]);
        $estimateRuns->addColumn('status', Types::STRING, ['length' => 24]);
        $estimateRuns->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $estimateRuns->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $estimateRuns->addIndex(
            ['home_id', 'as_of', 'status'],
            'idx_consumption_runs_home_time',
        );

        $estimates = $schema->createTable('consumption_estimates');
        $this->homeId($estimates);
        $estimates->addColumn('run_id', Types::STRING, ['length' => 36]);
        $estimates->addColumn('home_product_id', Types::STRING, ['length' => 36]);
        $estimates->addColumn('method', Types::STRING, ['length' => 40]);
        $estimates->addColumn('daily_rate', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $estimates->addColumn('variability', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $estimates->addColumn('sample_intervals', Types::INTEGER);
        $estimates->addColumn('coverage_days', Types::INTEGER);
        $estimates->addColumn('purchase_samples', Types::INTEGER);
        $estimates->addColumn('purchase_cadence_days', Types::INTEGER, [
            'notnull' => false,
        ]);
        $estimates->addColumn('next_expected_shopping_at', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
        ]);
        $estimates->addColumn('confidence_score', Types::DECIMAL, ['precision' => 5, 'scale' => 4]);
        $estimates->addColumn('confidence_band', Types::STRING, ['length' => 16]);
        $estimates->addColumn('evidence_from', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
        ]);
        $estimates->addColumn('evidence_to', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
        ]);
        $estimates->addColumn('limitations_json', Types::TEXT);
        $estimates->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $estimates->addForeignKeyConstraint('consumption_estimate_runs', ['run_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);
        $estimates->addForeignKeyConstraint('home_products', ['home_product_id'], ['id']);
        $estimates->addUniqueIndex(
            ['run_id', 'home_product_id'],
            'uniq_consumption_estimate_product',
        );
        $estimates->addIndex(
            ['home_id', 'home_product_id', 'created_at'],
            'idx_consumption_estimates_product',
        );

        $suggestionRuns = $schema->createTable('shopping_suggestion_runs');
        $this->homeId($suggestionRuns);
        $suggestionRuns->addColumn('estimate_run_id', Types::STRING, ['length' => 36]);
        $suggestionRuns->addColumn('model_version', Types::STRING, ['length' => 64]);
        $suggestionRuns->addColumn('as_of', Types::DATETIME_IMMUTABLE);
        $suggestionRuns->addColumn('horizon_days', Types::INTEGER);
        $suggestionRuns->addColumn('input_watermark', Types::STRING, ['length' => 64]);
        $suggestionRuns->addColumn('status', Types::STRING, ['length' => 24]);
        $suggestionRuns->addColumn('created_by_user_id', Types::STRING, ['length' => 36]);
        $suggestionRuns->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $suggestionRuns->addForeignKeyConstraint(
            'consumption_estimate_runs',
            ['estimate_run_id'],
            ['id'],
        );
        $suggestionRuns->addIndex(
            ['home_id', 'as_of', 'status'],
            'idx_suggestion_runs_home_time',
        );

        $suggestions = $schema->createTable('shopping_suggestions');
        $this->homeId($suggestions);
        $suggestions->addColumn('run_id', Types::STRING, ['length' => 36]);
        $suggestions->addColumn('home_product_id', Types::STRING, ['length' => 36]);
        $suggestions->addColumn('expected_demand', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $suggestions->addColumn('safety_stock', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $suggestions->addColumn('factual_stock', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $suggestions->addColumn('usable_stock', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $suggestions->addColumn('required_quantity', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $suggestions->addColumn('selected_pack_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $suggestions->addColumn('pack_count', Types::INTEGER, ['notnull' => false]);
        $suggestions->addColumn('confidence_score', Types::DECIMAL, ['precision' => 5, 'scale' => 4]);
        $suggestions->addColumn('confidence_band', Types::STRING, ['length' => 16]);
        $suggestions->addColumn('status', Types::STRING, ['length' => 24]);
        $suggestions->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $suggestions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $suggestions->addForeignKeyConstraint('shopping_suggestion_runs', ['run_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);
        $suggestions->addForeignKeyConstraint('home_products', ['home_product_id'], ['id']);
        $suggestions->addForeignKeyConstraint('product_packs', ['selected_pack_id'], ['id']);
        $suggestions->addUniqueIndex(
            ['run_id', 'home_product_id'],
            'uniq_shopping_suggestion_product',
        );
        $suggestions->addIndex(
            ['home_id', 'status', 'expires_at'],
            'idx_shopping_suggestions_active',
        );

        $explanations = $schema->createTable('suggestion_explanations');
        $explanations->addColumn('suggestion_id', Types::STRING, ['length' => 36]);
        $explanations->addColumn('factors_json', Types::TEXT);
        $explanations->addColumn('limitations_json', Types::TEXT);
        $explanations->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $explanations->setPrimaryKey(['suggestion_id']);
        $explanations->addForeignKeyConstraint('shopping_suggestions', ['suggestion_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);

        $options = $schema->createTable('suggestion_pack_options');
        $this->homeId($options);
        $options->addColumn('suggestion_id', Types::STRING, ['length' => 36]);
        $options->addColumn('pack_id', Types::STRING, ['length' => 36]);
        $options->addColumn('store_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $options->addColumn('currency', Types::STRING, ['length' => 3]);
        $options->addColumn('pack_count', Types::INTEGER);
        $options->addColumn('effective_total', Types::DECIMAL, ['precision' => 20, 'scale' => 2]);
        $options->addColumn('excess_quantity', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $options->addColumn('price_observed_at', Types::DATETIME_IMMUTABLE);
        $options->addColumn('selected', Types::BOOLEAN, ['default' => false]);
        $options->addColumn('reason', Types::STRING, ['length' => 191]);
        $options->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $options->addForeignKeyConstraint('shopping_suggestions', ['suggestion_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);
        $options->addForeignKeyConstraint('product_packs', ['pack_id'], ['id']);
        $options->addForeignKeyConstraint('stores', ['store_id'], ['id']);
        $options->addIndex(
            ['suggestion_id', 'currency', 'effective_total'],
            'idx_suggestion_pack_options_cost',
        );

        $preferenceRevisions = $schema->createTable('stock_preference_revisions');
        $this->homeId($preferenceRevisions);
        $preferenceRevisions->addColumn('home_product_id', Types::STRING, ['length' => 36]);
        $preferenceRevisions->addColumn('revision', Types::INTEGER);
        $preferenceRevisions->addColumn('minimum_quantity', Types::DECIMAL, [
            'precision' => 20,
            'scale' => 8,
            'notnull' => false,
        ]);
        $preferenceRevisions->addColumn('always_keep', Types::BOOLEAN);
        $preferenceRevisions->addColumn('never_suggest', Types::BOOLEAN);
        $preferenceRevisions->addColumn('preferred_pack_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $preferenceRevisions->addColumn('lead_time_days', Types::INTEGER);
        $preferenceRevisions->addColumn('target_coverage_days', Types::INTEGER, [
            'notnull' => false,
        ]);
        $preferenceRevisions->addColumn('snooze_until', Types::DATE_IMMUTABLE, [
            'notnull' => false,
        ]);
        $preferenceRevisions->addColumn('preference_json', Types::TEXT);
        $preferenceRevisions->addColumn('changed_at', Types::DATETIME_IMMUTABLE);
        $preferenceRevisions->addForeignKeyConstraint('home_products', ['home_product_id'], ['id']);
        $preferenceRevisions->addUniqueIndex(
            ['home_id', 'home_product_id', 'revision'],
            'uniq_stock_preference_revision',
        );
        $preferenceRevisions->addIndex(
            ['home_id', 'changed_at'],
            'idx_stock_preference_revisions_time',
        );

        $feedback = $schema->createTable('user_suggestion_feedback');
        $this->homeId($feedback);
        $feedback->addColumn('suggestion_id', Types::STRING, ['length' => 36]);
        $feedback->addColumn('actor_user_id', Types::STRING, ['length' => 36]);
        $feedback->addColumn('decision', Types::STRING, ['length' => 24]);
        $feedback->addColumn('original_quantity', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $feedback->addColumn('result_quantity', Types::DECIMAL, [
            'precision' => 20,
            'scale' => 8,
            'notnull' => false,
        ]);
        $feedback->addColumn('reason', Types::STRING, ['length' => 191]);
        $feedback->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $feedback->addForeignKeyConstraint('shopping_suggestions', ['suggestion_id'], ['id']);
        $feedback->addIndex(
            ['home_id', 'suggestion_id', 'created_at'],
            'idx_suggestion_feedback_history',
        );

        $models = $schema->createTable('suggestion_model_versions');
        $models->addColumn('model_key', Types::STRING, ['length' => 64]);
        $models->addColumn('version', Types::STRING, ['length' => 32]);
        $models->addColumn('checksum', Types::STRING, ['length' => 64]);
        $models->addColumn('status', Types::STRING, ['length' => 24]);
        $models->addColumn('parameter_schema_json', Types::TEXT);
        $models->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $models->setPrimaryKey(['model_key', 'version']);

        $backtests = $schema->createTable('suggestion_backtest_runs');
        $this->homeId($backtests);
        $backtests->addColumn('model_version', Types::STRING, ['length' => 64]);
        $backtests->addColumn('cutoffs_json', Types::TEXT);
        $backtests->addColumn('evaluation_days', Types::INTEGER);
        $backtests->addColumn('status', Types::STRING, ['length' => 24]);
        $backtests->addColumn('requested_by_user_id', Types::STRING, ['length' => 36]);
        $backtests->addColumn('metrics_json', Types::TEXT);
        $backtests->addColumn('limitations_json', Types::TEXT);
        $backtests->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $backtests->addColumn('completed_at', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
        ]);
        $backtests->addIndex(
            ['home_id', 'created_at', 'status'],
            'idx_suggestion_backtests_home',
        );

        $backtestResults = $schema->createTable('suggestion_backtest_results');
        $this->homeId($backtestResults);
        $backtestResults->addColumn('run_id', Types::STRING, ['length' => 36]);
        $backtestResults->addColumn('cutoff_at', Types::DATETIME_IMMUTABLE);
        $backtestResults->addColumn('home_product_id', Types::STRING, ['length' => 36]);
        $backtestResults->addColumn('suggested', Types::BOOLEAN);
        $backtestResults->addColumn('purchased_later', Types::BOOLEAN);
        $backtestResults->addColumn('suggested_quantity', Types::DECIMAL, [
            'precision' => 20,
            'scale' => 8,
        ]);
        $backtestResults->addColumn('confidence_band', Types::STRING, ['length' => 16]);
        $backtestResults->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $backtestResults->addForeignKeyConstraint('suggestion_backtest_runs', ['run_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);
        $backtestResults->addForeignKeyConstraint('home_products', ['home_product_id'], ['id']);
        $backtestResults->addIndex(
            ['run_id', 'cutoff_at'],
            'idx_backtest_results_cutoff',
        );
    }

    public function down(Schema $schema): void
    {
        foreach (
            [
                'suggestion_backtest_results',
                'suggestion_backtest_runs',
                'suggestion_model_versions',
                'user_suggestion_feedback',
                'stock_preference_revisions',
                'suggestion_pack_options',
                'suggestion_explanations',
                'shopping_suggestions',
                'shopping_suggestion_runs',
                'consumption_estimates',
                'consumption_estimate_runs',
            ] as $table
        ) {
            $schema->dropTable($table);
        }
        $schema->getTable('stock_threshold_preferences')->dropColumn('target_coverage_days');
        $schema->getTable('stock_threshold_preferences')->dropColumn('snooze_until');
        $schema->getTable('stock_count_sessions')->dropColumn('scope_complete');
        $schema->getTable('stock_count_sessions')->dropColumn('reliability');
    }

    private function homeId(Table $table): void
    {
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->addColumn('home_id', Types::STRING, ['length' => 36]);
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
    }
}
