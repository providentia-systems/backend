<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825002100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bind login-link capabilities to one application and revision every terminal transition';
    }

    public function up(Schema $schema): void
    {
        $requests = $schema->getTable('auth_login_link_requests');
        $requests->addColumn('application_kind', Types::STRING, [
            'length' => 16,
            'default' => 'homeowner',
        ]);
        $requests->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $requests->addIndex(
            ['application_kind', 'status', 'expires_at'],
            'idx_login_link_application_state',
        );
    }

    public function down(Schema $schema): void
    {
        $requests = $schema->getTable('auth_login_link_requests');
        $requests->dropIndex('idx_login_link_application_state');
        $requests->dropColumn('revision');
        $requests->dropColumn('application_kind');
    }
}
