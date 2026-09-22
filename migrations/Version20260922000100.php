<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add household global-category selection and explicit stock-unit labels without changing stock';
    }

    public function up(Schema $schema): void
    {
        $products = $schema->getTable('home_products');
        $products->addColumn('global_category_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $products->addColumn('unit', Types::STRING, ['length' => 16, 'default' => 'units']);
        $products->addIndex(['global_category_id'], 'idx_home_products_global_category');
        $products->addForeignKeyConstraint('categories', ['global_category_id'], ['id'], [], 'fk_home_product_global_category');
    }

    public function down(Schema $schema): void
    {
        $products = $schema->getTable('home_products');
        $products->removeForeignKey('fk_home_product_global_category');
        $products->dropIndex('idx_home_products_global_category');
        $products->dropColumn('global_category_id');
        $products->dropColumn('unit');
    }
}
