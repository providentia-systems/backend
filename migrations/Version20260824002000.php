<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Encrypted product-image contribution quarantine and attribution-free public catalog assets';
    }

    public function up(Schema $schema): void
    {
        $quarantine = $schema->createTable('catalog_contribution_images');
        $quarantine->addColumn('contribution_id', Types::STRING, ['length' => 36]);
        $this->imageColumns($quarantine);
        $quarantine->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $quarantine->setPrimaryKey(['contribution_id']);
        $quarantine->addForeignKeyConstraint(
            'catalog_contributions',
            ['contribution_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );

        $assets = $schema->createTable('catalog_public_assets');
        $assets->addColumn('id', Types::STRING, ['length' => 36]);
        $this->imageColumns($assets);
        $assets->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $assets->setPrimaryKey(['id']);
        $assets->addUniqueIndex(['asset_digest'], 'uniq_catalog_public_asset_digest');

        $publications = $schema->createTable('catalog_contribution_image_publications');
        $publications->addColumn('contribution_id', Types::STRING, ['length' => 36]);
        $publications->addColumn('contribution_revision', Types::INTEGER);
        $publications->addColumn('product_id', Types::STRING, ['length' => 36]);
        $publications->addColumn('icon_id', Types::STRING, ['length' => 36]);
        $publications->addColumn('icon_revision', Types::INTEGER);
        $publications->addColumn('public_asset_id', Types::STRING, ['length' => 36]);
        $publications->addColumn('published_by_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $publications->addColumn('published_at', Types::DATETIME_IMMUTABLE);
        $publications->setPrimaryKey(['contribution_id']);
        $publications->addForeignKeyConstraint(
            'catalog_contributions',
            ['contribution_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );
        $publications->addForeignKeyConstraint('products', ['product_id'], ['id']);
        $publications->addForeignKeyConstraint('catalog_icons', ['icon_id'], ['id']);
        $publications->addForeignKeyConstraint('catalog_public_assets', ['public_asset_id'], ['id']);
        $publications->addForeignKeyConstraint(
            'users',
            ['published_by_user_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
        );
        $publications->addUniqueIndex(
            ['icon_id', 'icon_revision'],
            'uniq_catalog_contribution_image_icon_revision',
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('catalog_contribution_image_publications');
        $schema->dropTable('catalog_public_assets');
        $schema->dropTable('catalog_contribution_images');
    }

    private function imageColumns(\Doctrine\DBAL\Schema\Table $table): void
    {
        $table->addColumn('asset_digest', Types::STRING, ['length' => 64]);
        $table->addColumn('media_type', Types::STRING, ['length' => 32]);
        $table->addColumn('width', Types::INTEGER);
        $table->addColumn('height', Types::INTEGER);
        $table->addColumn('byte_size', Types::INTEGER);
        $table->addColumn('ciphertext', Types::BLOB, ['length' => 5300000]);
        $table->addColumn('nonce', Types::BINARY, ['length' => 24, 'fixed' => true]);
        $table->addColumn('key_version', Types::INTEGER);
    }
}
