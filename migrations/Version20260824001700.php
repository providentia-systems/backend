<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824001700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Home-private inventory categories and product category assignment';
    }

    public function up(Schema $schema): void
    {
        $categories = $schema->createTable('home_categories');
        $categories->addColumn('id', Types::STRING, ['length' => 36]);
        $categories->addColumn('home_id', Types::STRING, ['length' => 36]);
        $categories->addColumn('name', Types::STRING, ['length' => 191]);
        $categories->addColumn('normalized_name', Types::STRING, ['length' => 191]);
        $categories->addColumn('status', Types::STRING, ['length' => 24]);
        $categories->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $categories->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $categories->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $categories->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $categories->setPrimaryKey(['id']);
        $categories->addUniqueIndex(
            ['home_id', 'normalized_name'],
            'uniq_home_categories_name',
        );
        $categories->addIndex(['home_id', 'status', 'name'], 'idx_home_categories_state');
        $categories->addForeignKeyConstraint(
            'homes',
            ['home_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );

        $products = $schema->getTable('home_products');
        $products->addColumn('home_category_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        // Category IDs are globally unique UUIDs. Every inventory read/write
        // additionally predicates both category and product by home_id. A
        // composite SET NULL foreign key would also null the non-null product
        // home_id on MySQL/MariaDB and is therefore not portable; keep the
        // identifier FK plus the tested application tenant predicate.
        $products->addForeignKeyConstraint(
            'home_categories',
            ['home_category_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_home_product_private_category',
        );
        $products->addIndex(
            ['home_id', 'home_category_id', 'status'],
            'idx_home_products_private_category',
        );
    }

    public function down(Schema $schema): void
    {
        $products = $schema->getTable('home_products');
        $products->removeForeignKey('fk_home_product_private_category');
        $products->dropIndex('idx_home_products_private_category');
        $products->dropColumn('home_category_id');
        $schema->dropTable('home_categories');
    }
}
