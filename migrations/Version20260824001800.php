<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824001800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revision-bound platform account lifecycle state';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $users->addColumn('status_changed_at', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
        ]);
        $users->addColumn('suspended_at', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
        ]);
        $users->addColumn('closed_at', Types::DATETIME_IMMUTABLE, [
            'notnull' => false,
        ]);
        $users->addIndex(['status', 'updated_at', 'id'], 'idx_users_operator_listing');
    }

    public function down(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->dropIndex('idx_users_operator_listing');
        $users->dropColumn('closed_at');
        $users->dropColumn('suspended_at');
        $users->dropColumn('status_changed_at');
        $users->dropColumn('revision');
    }
}
