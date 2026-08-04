<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Home permission policies, revocable invitations, and accepted ownership transfers';
    }

    public function up(Schema $schema): void
    {
        $policies = $schema->createTable('home_role_policies');
        $policies->addColumn('home_id', Types::STRING, ['length' => 36]);
        $policies->addColumn('role', Types::STRING, ['length' => 16]);
        $policies->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $policies->addColumn('updated_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $policies->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $policies->setPrimaryKey(['home_id', 'role']);
        $policies->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $policies->addForeignKeyConstraint('users', ['updated_by_user_id'], ['id'], ['onDelete' => 'SET NULL']);

        $grants = $schema->createTable('home_role_permission_grants');
        $grants->addColumn('home_id', Types::STRING, ['length' => 36]);
        $grants->addColumn('role', Types::STRING, ['length' => 16]);
        $grants->addColumn('permission', Types::STRING, ['length' => 80]);
        $grants->setPrimaryKey(['home_id', 'role', 'permission']);
        $grants->addForeignKeyConstraint(
            'home_role_policies',
            ['home_id', 'role'],
            ['home_id', 'role'],
            ['onDelete' => 'CASCADE'],
            'fk_home_permission_policy',
        );
        $grants->addIndex(['home_id', 'permission'], 'idx_home_permission_lookup');

        $invitations = $schema->getTable('home_invitations');
        $invitations->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $invitations->addColumn('revoked_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $invitations->addColumn('updated_at', Types::DATETIME_IMMUTABLE, [
            'default' => '1970-01-01 00:00:00',
        ]);
        $invitations->addForeignKeyConstraint(
            'users',
            ['revoked_by_user_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_home_invitation_revoker',
        );

        $transfers = $schema->createTable('home_ownership_transfers');
        $transfers->addColumn('id', Types::STRING, ['length' => 36]);
        $transfers->addColumn('home_id', Types::STRING, ['length' => 36]);
        $transfers->addColumn('proposed_by_user_id', Types::STRING, ['length' => 36]);
        $transfers->addColumn('target_user_id', Types::STRING, ['length' => 36]);
        $transfers->addColumn('expected_target_revision', Types::INTEGER);
        $transfers->addColumn('status', Types::STRING, ['length' => 16]);
        $transfers->addColumn('active_key', Types::STRING, ['length' => 8, 'notnull' => false]);
        $transfers->addColumn('step_up_verified_at', Types::DATETIME_IMMUTABLE);
        $transfers->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $transfers->addColumn('accepted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $transfers->addColumn('rejected_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $transfers->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $transfers->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $transfers->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $transfers->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $transfers->setPrimaryKey(['id']);
        $transfers->addForeignKeyConstraint('homes', ['home_id'], ['id'], ['onDelete' => 'CASCADE']);
        $transfers->addForeignKeyConstraint('users', ['proposed_by_user_id'], ['id']);
        $transfers->addForeignKeyConstraint('users', ['target_user_id'], ['id']);
        $transfers->addUniqueIndex(['home_id', 'active_key'], 'uniq_home_active_ownership_transfer');
        $transfers->addIndex(['target_user_id', 'status', 'expires_at'], 'idx_ownership_target_state');
        $transfers->addIndex(['proposed_by_user_id', 'status'], 'idx_ownership_proposer_state');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('home_ownership_transfers');

        $invitations = $schema->getTable('home_invitations');
        $invitations->removeForeignKey('fk_home_invitation_revoker');
        $invitations->dropColumn('updated_at');
        $invitations->dropColumn('revoked_by_user_id');
        $invitations->dropColumn('revision');

        $schema->dropTable('home_role_permission_grants');
        $schema->dropTable('home_role_policies');
    }
}
