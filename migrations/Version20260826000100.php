<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove human-account password storage; email login-link exchange is the only mailbox proof';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->dropColumn('password_hash');
        $users->dropColumn('password_changed_at');
        $users->dropColumn('failed_login_count');
        $users->dropColumn('locked_until');
    }

    public function down(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->addColumn('password_hash', Types::STRING, ['length' => 255, 'notnull' => false]);
        $users->addColumn('password_changed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $users->addColumn('failed_login_count', Types::INTEGER, ['default' => 0]);
        $users->addColumn('locked_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }
}
