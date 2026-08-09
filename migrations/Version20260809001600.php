<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809001600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cross-device login links, transport-specific sessions, and administrator email grants';
    }

    public function up(Schema $schema): void
    {
        $devices = $schema->getTable('devices');
        $devices->addColumn('installation_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $devices->addIndex(['user_id', 'installation_id'], 'idx_devices_user_installation');

        $sessions = $schema->getTable('auth_sessions');
        $sessions->addColumn('transport', Types::STRING, [
            'length' => 16,
            'default' => 'native',
        ]);
        $sessions->addColumn('refresh_idle_ttl_seconds', Types::INTEGER, [
            'default' => 2592000,
        ]);

        $schema->getTable('authentication_rate_limits')->addIndex(
            ['updated_at'],
            'idx_auth_rate_limits_updated',
        );

        $requests = $schema->createTable('auth_login_link_requests');
        $requests->addColumn('id', Types::STRING, ['length' => 36]);
        $requests->addColumn('request_hash', Types::STRING, ['length' => 64]);
        $requests->addColumn('normalized_email', Types::STRING, ['length' => 254]);
        $requests->addColumn('installation_id', Types::STRING, ['length' => 36]);
        $requests->addColumn('device_name', Types::STRING, ['length' => 120]);
        $requests->addColumn('platform', Types::STRING, ['length' => 40]);
        $requests->addColumn('transport', Types::STRING, ['length' => 16]);
        $requests->addColumn('refresh_idle_ttl_seconds', Types::INTEGER);
        $requests->addColumn('poll_challenge', Types::STRING, ['length' => 64]);
        $requests->addColumn('code_challenge', Types::STRING, ['length' => 64]);
        $requests->addColumn('state_hash', Types::STRING, ['length' => 64]);
        $requests->addColumn('approval_token_hash', Types::STRING, [
            'length' => 64,
            'notnull' => false,
        ]);
        $requests->addColumn('status', Types::STRING, ['length' => 16]);
        $requests->addColumn('failed_proof_attempts', Types::INTEGER, ['default' => 0]);
        $requests->addColumn('user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $requests->addColumn('onboarding_home_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $requests->addColumn('issued_session_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $requests->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $requests->addColumn('approved_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('exchange_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('exchanged_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('denied_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('cancelled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $requests->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $requests->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $requests->setPrimaryKey(['id']);
        $requests->addForeignKeyConstraint(
            'users',
            ['user_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_login_link_user',
        );
        $requests->addForeignKeyConstraint(
            'homes',
            ['onboarding_home_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_login_link_home',
        );
        $requests->addForeignKeyConstraint(
            'auth_sessions',
            ['issued_session_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_login_link_session',
        );
        $requests->addUniqueIndex(['poll_challenge'], 'uniq_login_link_poll_challenge');
        $requests->addUniqueIndex(['approval_token_hash'], 'uniq_login_link_approval_hash');
        $requests->addUniqueIndex(['issued_session_id'], 'uniq_login_link_session');
        $requests->addIndex(
            ['normalized_email', 'status', 'expires_at'],
            'idx_login_link_email_state',
        );
        $requests->addIndex(['status', 'expires_at'], 'idx_login_link_state_expiry');

        $roles = $schema->getTable('user_platform_roles');
        $roles->addColumn('granted_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $roles->addColumn('source', Types::STRING, [
            'length' => 24,
            'default' => 'legacy_cli',
        ]);
        $roles->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $roles->addColumn('updated_at', Types::DATETIME_IMMUTABLE, [
            'default' => '1970-01-01 00:00:00',
        ]);
        $roles->addForeignKeyConstraint(
            'users',
            ['granted_by_user_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_platform_role_grant_actor',
        );

        $emailGrants = $schema->createTable('platform_administrator_email_grants');
        $emailGrants->addColumn('id', Types::STRING, ['length' => 36]);
        $emailGrants->addColumn('normalized_email', Types::STRING, ['length' => 254]);
        $emailGrants->addColumn('status', Types::STRING, ['length' => 16]);
        $emailGrants->addColumn('source', Types::STRING, ['length' => 24]);
        $emailGrants->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $emailGrants->addColumn('granted_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $emailGrants->addColumn('accepted_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $emailGrants->addColumn('accepted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $emailGrants->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $emailGrants->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $emailGrants->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $emailGrants->setPrimaryKey(['id']);
        $emailGrants->addUniqueIndex(['normalized_email'], 'uniq_platform_admin_email_grant');
        $emailGrants->addIndex(['status', 'updated_at'], 'idx_platform_admin_email_state');
        $emailGrants->addForeignKeyConstraint(
            'users',
            ['granted_by_user_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_platform_admin_email_actor',
        );
        $emailGrants->addForeignKeyConstraint(
            'users',
            ['accepted_by_user_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_platform_admin_email_recipient',
        );

        $this->addSql(
            "INSERT INTO home_role_permission_grants (home_id, role, permission)
             SELECT p.home_id, p.role, 'home.manage'
             FROM home_role_policies p
             WHERE p.role IN ('owner', 'manager')
               AND NOT EXISTS (
                   SELECT 1 FROM home_role_permission_grants g
                   WHERE g.home_id = p.home_id AND g.role = p.role
                     AND g.permission = 'home.manage'
               )",
        );
    }

    public function down(Schema $schema): void
    {
        // Permission grants are durable policy data. A rollback cannot
        // distinguish this migration's manager backfill from a pre-existing
        // or subsequently customized grant, so it must not delete them.
        $schema->dropTable('platform_administrator_email_grants');

        $roles = $schema->getTable('user_platform_roles');
        $roles->removeForeignKey('fk_platform_role_grant_actor');
        $roles->dropColumn('updated_at');
        $roles->dropColumn('revision');
        $roles->dropColumn('source');
        $roles->dropColumn('granted_by_user_id');

        $schema->dropTable('auth_login_link_requests');

        $sessions = $schema->getTable('auth_sessions');
        $sessions->dropColumn('refresh_idle_ttl_seconds');
        $sessions->dropColumn('transport');

        $schema->getTable('authentication_rate_limits')->dropIndex('idx_auth_rate_limits_updated');

        $devices = $schema->getTable('devices');
        $devices->dropIndex('idx_devices_user_installation');
        $devices->dropColumn('installation_id');
    }
}
