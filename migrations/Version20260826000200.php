<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow durable trusted-device sessions with no idle expiry';
    }

    public function up(Schema $schema): void
    {
        $sessions = $schema->getTable('auth_sessions');
        $sessions->getColumn('refresh_expires_at')->setNotnull(false);
        $sessions->getColumn('refresh_idle_ttl_seconds')->setDefault(0);
    }

    public function down(Schema $schema): void
    {
        $sessions = $schema->getTable('auth_sessions');
        $sessions->getColumn('refresh_expires_at')->setNotnull(true);
        $sessions->getColumn('refresh_idle_ttl_seconds')->setDefault(2592000);
    }
}
