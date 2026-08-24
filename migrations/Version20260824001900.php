<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824001900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Durably link approved product contributions to governed catalog proposals';
    }

    public function up(Schema $schema): void
    {
        $links = $schema->createTable('catalog_contribution_proposals');
        $links->addColumn('contribution_id', Types::STRING, ['length' => 36]);
        $links->addColumn('contribution_revision', Types::INTEGER);
        $links->addColumn('proposal_id', Types::STRING, ['length' => 36]);
        $links->addColumn('published_category_id', Types::STRING, ['length' => 36]);
        $links->addColumn('linked_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $links->addColumn('linked_at', Types::DATETIME_IMMUTABLE);
        $links->setPrimaryKey(['contribution_id']);
        $links->addUniqueIndex(['proposal_id'], 'uniq_catalog_contribution_proposal');
        $links->addIndex(['published_category_id'], 'idx_catalog_contribution_proposal_category');
        $links->addForeignKeyConstraint(
            'catalog_contributions',
            ['contribution_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );
        $links->addForeignKeyConstraint(
            'catalog_proposals',
            ['proposal_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );
        $links->addForeignKeyConstraint('categories', ['published_category_id'], ['id']);
        $links->addForeignKeyConstraint(
            'users',
            ['linked_by_user_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('catalog_contribution_proposals');
    }
}
