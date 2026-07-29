<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2 identity, home and global catalog plus Phase 4 synchronization';
    }

    public function up(Schema $schema): void
    {
        $this->identity($schema);
        $this->homes($schema);
        $this->catalog($schema);
        $this->synchronization($schema);
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'sync_cursors',
            'record_tombstones',
            'change_log',
            'client_operations',
            'sync_documents',
            'catalog_seed_runs',
            'catalog_seed_quarantine',
            'catalog_merge_events',
            'catalog_revisions',
            'catalog_proposals',
            'catalog_icons',
            'product_identity_rules',
            'product_barcodes',
            'product_aliases',
            'product_packs',
            'product_variants',
            'products',
            'units',
            'categories',
            'support_access_grants',
            'audit_events',
            'home_invitations',
            'home_memberships',
            'homes',
            'authentication_rate_limits',
            'user_platform_roles',
            'auth_refresh_history',
            'auth_sessions',
            'auth_one_time_tokens',
            'devices',
            'user_profiles',
            'users',
        ] as $table) {
            $schema->dropTable($table);
        }
    }

    private function identity(Schema $schema): void
    {
        $users = $schema->createTable('users');
        $this->id($users);
        $users->addColumn('email', Types::STRING, ['length' => 254]);
        $users->addColumn('normalized_email', Types::STRING, ['length' => 254]);
        $users->addColumn('password_hash', Types::STRING, ['length' => 255]);
        $users->addColumn('status', Types::STRING, ['length' => 24]);
        $users->addColumn('email_verified_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $users->addColumn('failed_login_count', Types::INTEGER, ['default' => 0]);
        $users->addColumn('locked_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $users->addColumn('password_changed_at', Types::DATETIME_IMMUTABLE);
        $this->timestamps($users);
        $users->addUniqueIndex(['normalized_email'], 'uniq_users_normalized_email');
        $users->addIndex(['status'], 'idx_users_status');

        $profiles = $schema->createTable('user_profiles');
        $profiles->addColumn('user_id', Types::STRING, ['length' => 36]);
        $profiles->addColumn('display_name', Types::STRING, ['length' => 120]);
        $profiles->addColumn('locale', Types::STRING, ['length' => 16]);
        $profiles->addColumn('timezone', Types::STRING, ['length' => 64]);
        $this->timestamps($profiles);
        $profiles->setPrimaryKey(['user_id']);
        $profiles->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

        $devices = $schema->createTable('devices');
        $this->id($devices);
        $devices->addColumn('user_id', Types::STRING, ['length' => 36]);
        $devices->addColumn('name', Types::STRING, ['length' => 120]);
        $devices->addColumn('platform', Types::STRING, ['length' => 40]);
        $devices->addColumn('last_seen_at', Types::DATETIME_IMMUTABLE);
        $devices->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $devices->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $devices->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $devices->addIndex(['user_id', 'revoked_at'], 'idx_devices_user_active');

        $tokens = $schema->createTable('auth_one_time_tokens');
        $this->id($tokens);
        $tokens->addColumn('user_id', Types::STRING, ['length' => 36]);
        $tokens->addColumn('purpose', Types::STRING, ['length' => 32]);
        $tokens->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $tokens->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $tokens->addColumn('consumed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tokens->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $tokens->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $tokens->addUniqueIndex(['token_hash'], 'uniq_auth_one_time_hash');
        $tokens->addIndex(['user_id', 'purpose', 'consumed_at'], 'idx_auth_one_time_user');

        $sessions = $schema->createTable('auth_sessions');
        $this->id($sessions);
        $sessions->addColumn('user_id', Types::STRING, ['length' => 36]);
        $sessions->addColumn('device_id', Types::STRING, ['length' => 36]);
        $sessions->addColumn('access_token_hash', Types::STRING, ['length' => 64]);
        $sessions->addColumn('refresh_token_hash', Types::STRING, ['length' => 64]);
        $sessions->addColumn('csrf_token_hash', Types::STRING, ['length' => 64]);
        $sessions->addColumn('active_home_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $sessions->addColumn('access_expires_at', Types::DATETIME_IMMUTABLE);
        $sessions->addColumn('refresh_expires_at', Types::DATETIME_IMMUTABLE);
        $sessions->addColumn('last_seen_at', Types::DATETIME_IMMUTABLE);
        $sessions->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $sessions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $sessions->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $sessions->addForeignKeyConstraint('devices', ['device_id'], ['id'], ['onDelete' => 'CASCADE']);
        $sessions->addUniqueIndex(['access_token_hash'], 'uniq_auth_access_hash');
        $sessions->addUniqueIndex(['refresh_token_hash'], 'uniq_auth_refresh_hash');
        $sessions->addIndex(['user_id', 'revoked_at'], 'idx_auth_sessions_user');

        $refresh = $schema->createTable('auth_refresh_history');
        $refresh->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $refresh->addColumn('session_id', Types::STRING, ['length' => 36]);
        $refresh->addColumn('rotated_at', Types::DATETIME_IMMUTABLE);
        $refresh->setPrimaryKey(['token_hash']);
        $refresh->addForeignKeyConstraint('auth_sessions', ['session_id'], ['id'], ['onDelete' => 'CASCADE']);
        $refresh->addIndex(['session_id'], 'idx_auth_refresh_history_session');

        $roles = $schema->createTable('user_platform_roles');
        $roles->addColumn('user_id', Types::STRING, ['length' => 36]);
        $roles->addColumn('role', Types::STRING, ['length' => 40]);
        $roles->addColumn('granted_at', Types::DATETIME_IMMUTABLE);
        $roles->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $roles->setPrimaryKey(['user_id', 'role']);
        $roles->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

        $limits = $schema->createTable('authentication_rate_limits');
        $limits->addColumn('bucket_hash', Types::STRING, ['length' => 64]);
        $limits->addColumn('attempts', Types::INTEGER, ['default' => 0]);
        $limits->addColumn('window_started_at', Types::DATETIME_IMMUTABLE);
        $limits->addColumn('blocked_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $limits->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $limits->setPrimaryKey(['bucket_hash']);
    }

    private function homes(Schema $schema): void
    {
        $homes = $schema->createTable('homes');
        $this->id($homes);
        $homes->addColumn('name', Types::STRING, ['length' => 120]);
        $homes->addColumn('default_locale', Types::STRING, ['length' => 16]);
        $homes->addColumn('default_currency', Types::STRING, ['length' => 3]);
        $homes->addColumn('default_timezone', Types::STRING, ['length' => 64]);
        $homes->addColumn('status', Types::STRING, ['length' => 24]);
        $homes->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $this->timestamps($homes);
        $homes->addIndex(['status'], 'idx_homes_status');

        $memberships = $schema->createTable('home_memberships');
        $memberships->addColumn('home_id', Types::STRING, ['length' => 36]);
        $memberships->addColumn('user_id', Types::STRING, ['length' => 36]);
        $memberships->addColumn('role', Types::STRING, ['length' => 16]);
        $memberships->addColumn('status', Types::STRING, ['length' => 16]);
        $memberships->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $memberships->addColumn('joined_at', Types::DATETIME_IMMUTABLE);
        $memberships->addColumn('left_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $memberships->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $memberships->setPrimaryKey(['home_id', 'user_id']);
        $memberships->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $memberships->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $memberships->addIndex(['home_id', 'status', 'role'], 'idx_memberships_home_role');
        $memberships->addIndex(['user_id', 'status'], 'idx_memberships_user');

        $invitations = $schema->createTable('home_invitations');
        $this->id($invitations);
        $invitations->addColumn('home_id', Types::STRING, ['length' => 36]);
        $invitations->addColumn('inviter_user_id', Types::STRING, ['length' => 36]);
        $invitations->addColumn('normalized_email', Types::STRING, ['length' => 254]);
        $invitations->addColumn('role', Types::STRING, ['length' => 16]);
        $invitations->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $invitations->addColumn('status', Types::STRING, ['length' => 16]);
        $invitations->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $invitations->addColumn('accepted_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $invitations->addColumn('accepted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $invitations->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $invitations->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $invitations->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $invitations->addForeignKeyConstraint('users', ['inviter_user_id'], ['id']);
        $invitations->addUniqueIndex(['token_hash'], 'uniq_home_invitation_token');
        $invitations->addIndex(['home_id', 'status', 'expires_at'], 'idx_home_invitation_state');

        $audit = $schema->createTable('audit_events');
        $this->id($audit);
        $audit->addColumn('home_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $audit->addColumn('actor_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $audit->addColumn('action', Types::STRING, ['length' => 120]);
        $audit->addColumn('target_type', Types::STRING, ['length' => 80]);
        $audit->addColumn('target_id', Types::STRING, ['length' => 64]);
        $audit->addColumn('details', Types::TEXT);
        $audit->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $audit->addIndex(['home_id', 'occurred_at'], 'idx_audit_home_time');
        $audit->addIndex(['actor_user_id', 'occurred_at'], 'idx_audit_actor_time');

        $support = $schema->createTable('support_access_grants');
        $this->id($support);
        $support->addColumn('home_id', Types::STRING, ['length' => 36]);
        $support->addColumn('grantor_user_id', Types::STRING, ['length' => 36]);
        $support->addColumn('support_user_id', Types::STRING, ['length' => 36]);
        $support->addColumn('scope_json', Types::TEXT);
        $support->addColumn('purpose', Types::STRING, ['length' => 191]);
        $support->addColumn('starts_at', Types::DATETIME_IMMUTABLE);
        $support->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $support->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $support->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $support->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $support->addIndex(['home_id', 'expires_at', 'revoked_at'], 'idx_support_home_active');
    }

    private function catalog(Schema $schema): void
    {
        $categories = $schema->createTable('categories');
        $this->catalogBase($categories);
        $categories->addColumn('parent_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $categories->addColumn('canonical_name', Types::STRING, ['length' => 191]);
        $categories->addColumn('normalized_name', Types::STRING, ['length' => 191]);
        $categories->addUniqueIndex(['normalized_name'], 'uniq_categories_normalized');

        $units = $schema->createTable('units');
        $this->catalogBase($units);
        $units->addColumn('symbol', Types::STRING, ['length' => 32]);
        $units->addColumn('name', Types::STRING, ['length' => 80]);
        $units->addColumn('dimension', Types::STRING, ['length' => 40]);
        $units->addColumn('base_factor', Types::DECIMAL, ['precision' => 20, 'scale' => 8]);
        $units->addUniqueIndex(['symbol', 'dimension'], 'uniq_units_symbol_dimension');

        $products = $schema->createTable('products');
        $this->catalogBase($products);
        $products->addColumn('category_id', Types::STRING, ['length' => 36]);
        $products->addColumn('canonical_name', Types::STRING, ['length' => 191]);
        $products->addColumn('normalized_name', Types::STRING, ['length' => 191]);
        $products->addColumn('brand', Types::STRING, ['length' => 120]);
        $products->addColumn('normalized_brand', Types::STRING, ['length' => 120]);
        $products->addForeignKeyConstraint('categories', ['category_id'], ['id']);
        $products->addUniqueIndex(
            ['category_id', 'normalized_name', 'normalized_brand'],
            'uniq_products_identity',
        );
        $products->addIndex(['normalized_name', 'normalized_brand'], 'idx_products_search');

        $variants = $schema->createTable('product_variants');
        $this->catalogBase($variants);
        $variants->addColumn('product_id', Types::STRING, ['length' => 36]);
        $variants->addColumn('canonical_label', Types::STRING, ['length' => 191]);
        $variants->addColumn('normalized_label', Types::STRING, ['length' => 191]);
        $variants->addColumn('attributes_json', Types::TEXT);
        $variants->addForeignKeyConstraint('products', ['product_id'], ['id'], ['onDelete' => 'CASCADE']);
        $variants->addUniqueIndex(['product_id', 'normalized_label'], 'uniq_product_variants_label');

        $packs = $schema->createTable('product_packs');
        $this->catalogBase($packs);
        $packs->addColumn('product_id', Types::STRING, ['length' => 36]);
        $packs->addColumn('variant_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $packs->addColumn('unit_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $packs->addColumn('source_key', Types::STRING, ['length' => 191]);
        $packs->addColumn('original_pack_text', Types::STRING, ['length' => 191]);
        $packs->addColumn('amount', Types::DECIMAL, ['precision' => 20, 'scale' => 8, 'notnull' => false]);
        $packs->addColumn('normalized_base_amount', Types::DECIMAL, [
            'precision' => 20,
            'scale' => 8,
            'notnull' => false,
        ]);
        $packs->addColumn('multiplicity', Types::INTEGER, ['default' => 1]);
        $packs->addForeignKeyConstraint('products', ['product_id'], ['id'], ['onDelete' => 'CASCADE']);
        $packs->addUniqueIndex(['source_key'], 'uniq_product_packs_source');
        $packs->addIndex(['product_id', 'status'], 'idx_product_packs_product');

        $aliases = $schema->createTable('product_aliases');
        $this->catalogBase($aliases);
        $aliases->addColumn('scope', Types::STRING, ['length' => 16]);
        $aliases->addColumn('home_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $aliases->addColumn('product_id', Types::STRING, ['length' => 36]);
        $aliases->addColumn('variant_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $aliases->addColumn('pack_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $aliases->addColumn('raw_alias', Types::STRING, ['length' => 191]);
        $aliases->addColumn('normalized_alias', Types::STRING, ['length' => 191]);
        $aliases->addColumn('approval_source', Types::STRING, ['length' => 191]);
        $aliases->addForeignKeyConstraint('products', ['product_id'], ['id'], ['onDelete' => 'CASCADE']);
        $aliases->addIndex(['scope', 'normalized_alias', 'status'], 'idx_product_alias_lookup');
        $aliases->addIndex(['home_id', 'normalized_alias'], 'idx_product_alias_home');

        $barcodes = $schema->createTable('product_barcodes');
        $this->catalogBase($barcodes);
        $barcodes->addColumn('pack_id', Types::STRING, ['length' => 36]);
        $barcodes->addColumn('barcode', Types::STRING, ['length' => 64]);
        $barcodes->addColumn('barcode_type', Types::STRING, ['length' => 24]);
        $barcodes->addColumn('verification_status', Types::STRING, ['length' => 24]);
        $barcodes->addUniqueIndex(['barcode'], 'uniq_product_barcodes_value');

        $rules = $schema->createTable('product_identity_rules');
        $this->catalogBase($rules);
        $rules->addColumn('rule_key', Types::STRING, ['length' => 80]);
        $rules->addColumn('family', Types::STRING, ['length' => 191]);
        $rules->addColumn('rule_definition', Types::TEXT);
        $rules->addColumn('provenance', Types::STRING, ['length' => 191]);
        $rules->addUniqueIndex(['rule_key'], 'uniq_identity_rules_key');

        foreach ([
            'catalog_icons' => ['target_type', 'target_id', 'asset_digest', 'media_type', 'provenance'],
            'catalog_proposals' => ['proposal_json', 'moderation_status', 'reviewed_by_user_id', 'reviewed_at'],
            'catalog_revisions' => ['entity_type', 'entity_id', 'before_json', 'after_json', 'reason'],
            'catalog_merge_events' => ['survivor_id', 'merged_ids_json', 'plan_json', 'reason', 'reversed_at'],
        ] as $name => $columns) {
            $table = $schema->createTable($name);
            $this->id($table);
            foreach ($columns as $column) {
                $date = str_ends_with($column, '_at');
                $table->addColumn(
                    $column,
                    $date ? Types::DATETIME_IMMUTABLE : Types::TEXT,
                    ['notnull' => ! $date],
                );
            }
            $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        }

        $quarantine = $schema->createTable('catalog_seed_quarantine');
        $this->id($quarantine);
        $quarantine->addColumn('raw_description', Types::STRING, ['length' => 191]);
        $quarantine->addColumn('reason', Types::STRING, ['length' => 80]);
        $quarantine->addColumn('resolution_status', Types::STRING, ['length' => 24]);
        $quarantine->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $quarantine->addUniqueIndex(
            ['raw_description', 'reason'],
            'uniq_catalog_seed_quarantine_row',
        );

        $runs = $schema->createTable('catalog_seed_runs');
        $this->id($runs);
        $runs->addColumn('seed_version', Types::STRING, ['length' => 80]);
        $runs->addColumn('pantry_data_sha256', Types::STRING, ['length' => 64]);
        $runs->addColumn('product_rules_sha256', Types::STRING, ['length' => 64]);
        $runs->addColumn('reconciliation', Types::TEXT);
        $runs->addColumn('completed_at', Types::DATETIME_IMMUTABLE);
        $runs->addUniqueIndex(
            ['seed_version', 'pantry_data_sha256', 'product_rules_sha256'],
            'uniq_catalog_seed_version_sources',
        );
        $runs->addIndex(['seed_version', 'completed_at'], 'idx_catalog_seed_run_version');
    }

    private function synchronization(Schema $schema): void
    {
        $documents = $schema->createTable('sync_documents');
        $documents->addColumn('home_id', Types::STRING, ['length' => 36]);
        $documents->addColumn('entity_type', Types::STRING, ['length' => 64]);
        $documents->addColumn('entity_id', Types::STRING, ['length' => 36]);
        $documents->addColumn('revision', Types::INTEGER);
        $documents->addColumn('payload_schema_version', Types::INTEGER);
        $documents->addColumn('payload_json', Types::TEXT);
        $documents->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $documents->addColumn('updated_by_user_id', Types::STRING, ['length' => 36]);
        $documents->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $documents->setPrimaryKey(['home_id', 'entity_type', 'entity_id']);
        $documents->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $documents->addIndex(['home_id', 'updated_at'], 'idx_sync_documents_home_updated');

        $operations = $schema->createTable('client_operations');
        $operations->addColumn('operation_id', Types::STRING, ['length' => 36]);
        $operations->addColumn('home_id', Types::STRING, ['length' => 36]);
        $operations->addColumn('user_id', Types::STRING, ['length' => 36]);
        $operations->addColumn('device_id', Types::STRING, ['length' => 36]);
        $operations->addColumn('entity_type', Types::STRING, ['length' => 64]);
        $operations->addColumn('entity_id', Types::STRING, ['length' => 36]);
        $operations->addColumn('operation_type', Types::STRING, ['length' => 64]);
        $operations->addColumn('base_revision', Types::INTEGER, ['notnull' => false]);
        $operations->addColumn('payload_schema_version', Types::INTEGER);
        $operations->addColumn('request_hash', Types::STRING, ['length' => 64]);
        $operations->addColumn('status', Types::STRING, ['length' => 32]);
        $operations->addColumn('response_json', Types::TEXT);
        $operations->addColumn('client_timestamp', Types::STRING, ['length' => 40]);
        $operations->addColumn('processed_at', Types::DATETIME_IMMUTABLE);
        $operations->setPrimaryKey(['operation_id']);
        $operations->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $operations->addIndex(['home_id', 'device_id', 'processed_at'], 'idx_client_ops_home_device');
        $operations->addIndex(['home_id', 'status', 'processed_at'], 'idx_client_ops_home_status');

        $changes = $schema->createTable('change_log');
        $changes->addColumn('sequence_id', Types::BIGINT, ['autoincrement' => true]);
        $changes->addColumn('home_id', Types::STRING, ['length' => 36]);
        $changes->addColumn('entity_type', Types::STRING, ['length' => 64]);
        $changes->addColumn('entity_id', Types::STRING, ['length' => 36]);
        $changes->addColumn('operation_type', Types::STRING, ['length' => 16]);
        $changes->addColumn('revision', Types::INTEGER);
        $changes->addColumn('payload_schema_version', Types::INTEGER);
        $changes->addColumn('payload_json', Types::TEXT);
        $changes->addColumn('changed_by_user_id', Types::STRING, ['length' => 36]);
        $changes->addColumn('changed_at', Types::DATETIME_IMMUTABLE);
        $changes->setPrimaryKey(['sequence_id']);
        $changes->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $changes->addIndex(['home_id', 'sequence_id'], 'idx_change_log_home_sequence');

        $tombstones = $schema->createTable('record_tombstones');
        $tombstones->addColumn('home_id', Types::STRING, ['length' => 36]);
        $tombstones->addColumn('entity_type', Types::STRING, ['length' => 64]);
        $tombstones->addColumn('entity_id', Types::STRING, ['length' => 36]);
        $tombstones->addColumn('revision', Types::INTEGER);
        $tombstones->addColumn('change_cursor', Types::BIGINT);
        $tombstones->addColumn('deleted_by_user_id', Types::STRING, ['length' => 36]);
        $tombstones->addColumn('deleted_at', Types::DATETIME_IMMUTABLE);
        $tombstones->addColumn('retain_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tombstones->setPrimaryKey(['home_id', 'entity_type', 'entity_id']);
        $tombstones->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $tombstones->addIndex(['home_id', 'change_cursor'], 'idx_tombstones_home_cursor');

        $cursors = $schema->createTable('sync_cursors');
        $cursors->addColumn('home_id', Types::STRING, ['length' => 36]);
        $cursors->addColumn('user_id', Types::STRING, ['length' => 36]);
        $cursors->addColumn('device_id', Types::STRING, ['length' => 36]);
        $cursors->addColumn('last_acknowledged_cursor', Types::BIGINT);
        $cursors->addColumn('schema_version', Types::INTEGER);
        $cursors->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $cursors->setPrimaryKey(['home_id', 'user_id', 'device_id']);
        $cursors->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $cursors->addIndex(['home_id', 'last_acknowledged_cursor'], 'idx_sync_cursors_home');
    }

    private function id(Table $table): void
    {
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->setPrimaryKey(['id']);
    }

    private function timestamps(Table $table): void
    {
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
    }

    private function catalogBase(Table $table): void
    {
        $this->id($table);
        $table->addColumn('status', Types::STRING, ['length' => 32]);
        $table->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $this->timestamps($table);
    }
}
