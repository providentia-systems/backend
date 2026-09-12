<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve shopping-line history when removing and restoring list items';
    }

    public function up(Schema $schema): void
    {
        $schema
            ->getTable('shopping_list_lines')
            ->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        foreach (['suggestion_id', 'selected_pack_id'] as $column) {
            $schema
                ->getTable('shopping_list_lines')
                ->addColumn($column, Types::STRING, ['length' => 36, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['archived_at', 'suggestion_id', 'selected_pack_id'] as $column) {
            $schema->getTable('shopping_list_lines')->dropColumn($column);
        }
    }
}
