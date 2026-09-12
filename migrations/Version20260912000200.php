<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Serialize global alias publication across curation, moderation and seed imports';
    }

    public function up(Schema $schema): void
    {
        $locks = $schema->createTable('catalog_governance_locks');
        $locks->addColumn('resource', Types::STRING, ['length' => 64]);
        $locks->setPrimaryKey(['resource']);
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->insert('catalog_governance_locks', ['resource' => 'global-alias-publication']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('catalog_governance_locks');
    }
}
