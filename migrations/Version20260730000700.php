<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 7 governed catalog proposals, conflicts, icons, redirects and reversible merges';
    }

    public function up(Schema $schema): void
    {
        $proposals = $schema->getTable('catalog_proposals');
        $proposals->modifyColumn('moderation_status', [
            'type' => Type::getType(Types::STRING),
            'length' => 24,
        ]);
        $proposals->addColumn('proposal_type', Types::STRING, ['length' => 32, 'default' => 'legacy']);
        $proposals->addColumn('normalized_key', Types::STRING, ['length' => 191, 'default' => '']);
        $proposals->addColumn('submitted_by_user_id', Types::STRING, ['length' => 36, 'default' => '']);
        $proposals->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $proposals->addColumn('duplicate_entity_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $proposals->addColumn('resolved_entity_type', Types::STRING, [
            'length' => 32,
            'notnull' => false,
        ]);
        $proposals->addColumn('resolved_entity_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $proposals->addColumn('moderation_reason', Types::STRING, [
            'length' => 500,
            'notnull' => false,
        ]);
        $proposals->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $proposals->addIndex(
            ['moderation_status', 'proposal_type', 'created_at'],
            'idx_catalog_proposals_workbench',
        );
        $proposals->addIndex(
            ['normalized_key', 'moderation_status'],
            'idx_catalog_proposals_normalized',
        );

        $icons = $schema->getTable('catalog_icons');
        $icons->addColumn('status', Types::STRING, ['length' => 24, 'default' => 'active']);
        $icons->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $icons->addColumn('alt_text', Types::STRING, ['length' => 191, 'default' => '']);
        $icons->addColumn('width', Types::INTEGER, ['notnull' => false]);
        $icons->addColumn('height', Types::INTEGER, ['notnull' => false]);
        $icons->addColumn('byte_size', Types::INTEGER, ['notnull' => false]);
        $icons->addColumn('updated_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $icons->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $icons->addIndex(['status', 'created_at'], 'idx_catalog_icons_status');

        $revisions = $schema->getTable('catalog_revisions');
        $revisions->addColumn('actor_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $revisions->addColumn('operation_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $revisions->addColumn('entity_key', Types::STRING, ['length' => 191, 'default' => '']);
        $revisions->addIndex(['entity_key', 'created_at'], 'idx_catalog_revision_entity');

        $merges = $schema->getTable('catalog_merge_events');
        $merges->addColumn('status', Types::STRING, ['length' => 24, 'default' => 'legacy']);
        $merges->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $merges->addColumn('applied_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $merges->addColumn('applied_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $merges->addColumn('reversed_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $merges->addColumn('reverse_reason', Types::STRING, [
            'length' => 500,
            'notnull' => false,
        ]);
        $merges->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $merges->addIndex(['status', 'created_at'], 'idx_catalog_merge_status');

        $conflicts = $schema->createTable('catalog_conflicts');
        $this->id($conflicts);
        $conflicts->addColumn('conflict_type', Types::STRING, ['length' => 32]);
        $conflicts->addColumn('conflict_key', Types::STRING, ['length' => 191]);
        $conflicts->addColumn('proposal_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $conflicts->addColumn('existing_entity_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $conflicts->addColumn('candidate_entity_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $conflicts->addColumn('details_json', Types::TEXT);
        $conflicts->addColumn('status', Types::STRING, ['length' => 24]);
        $conflicts->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $conflicts->addColumn('reviewed_by_user_id', Types::STRING, [
            'length' => 36,
            'notnull' => false,
        ]);
        $conflicts->addColumn('reviewed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->timestamps($conflicts);
        $conflicts->addForeignKeyConstraint('catalog_proposals', ['proposal_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);
        $conflicts->addIndex(
            ['conflict_type', 'status', 'created_at'],
            'idx_catalog_conflicts_workbench',
        );

        $redirects = $schema->createTable('catalog_product_redirects');
        $redirects->addColumn('duplicate_product_id', Types::STRING, ['length' => 36]);
        $redirects->addColumn('survivor_product_id', Types::STRING, ['length' => 36]);
        $redirects->addColumn('merge_event_id', Types::STRING, ['length' => 36]);
        $redirects->addColumn('status', Types::STRING, ['length' => 24]);
        $this->timestamps($redirects);
        $redirects->setPrimaryKey(['duplicate_product_id', 'merge_event_id']);
        $redirects->addForeignKeyConstraint('products', ['duplicate_product_id'], ['id']);
        $redirects->addForeignKeyConstraint('products', ['survivor_product_id'], ['id']);
        $redirects->addForeignKeyConstraint('catalog_merge_events', ['merge_event_id'], ['id']);
        $redirects->addIndex(
            ['survivor_product_id', 'status'],
            'idx_catalog_redirect_survivor',
        );
        $redirects->addIndex(
            ['duplicate_product_id', 'status'],
            'idx_catalog_redirect_duplicate',
        );

        $relinks = $schema->createTable('catalog_merge_relinks');
        $relinks->addColumn('merge_event_id', Types::STRING, ['length' => 36]);
        $relinks->addColumn('duplicate_product_id', Types::STRING, ['length' => 36]);
        $relinks->addColumn('reference_type', Types::STRING, ['length' => 32]);
        $relinks->addColumn('reference_id', Types::STRING, ['length' => 36]);
        $relinks->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $relinks->setPrimaryKey(['merge_event_id', 'reference_type', 'reference_id']);
        $relinks->addForeignKeyConstraint('catalog_merge_events', ['merge_event_id'], ['id'], [
            'onDelete' => 'CASCADE',
        ]);
        $relinks->addForeignKeyConstraint('products', ['duplicate_product_id'], ['id']);
        $relinks->addIndex(
            ['duplicate_product_id', 'reference_type'],
            'idx_catalog_relinks_duplicate',
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('catalog_merge_relinks');
        $schema->dropTable('catalog_product_redirects');
        $schema->dropTable('catalog_conflicts');

        $schema->getTable('catalog_merge_events')->dropIndex('idx_catalog_merge_status');
        $this->removeColumns($schema->getTable('catalog_merge_events'), [
            'status',
            'revision',
            'applied_by_user_id',
            'applied_at',
            'reversed_by_user_id',
            'reverse_reason',
            'updated_at',
        ]);
        $schema->getTable('catalog_revisions')->dropIndex('idx_catalog_revision_entity');
        $this->removeColumns($schema->getTable('catalog_revisions'), [
            'actor_user_id',
            'operation_id',
            'entity_key',
        ]);
        $schema->getTable('catalog_icons')->dropIndex('idx_catalog_icons_status');
        $this->removeColumns($schema->getTable('catalog_icons'), [
            'status',
            'revision',
            'alt_text',
            'width',
            'height',
            'byte_size',
            'updated_by_user_id',
            'updated_at',
        ]);
        $schema->getTable('catalog_proposals')->dropIndex('idx_catalog_proposals_workbench');
        $schema->getTable('catalog_proposals')->dropIndex('idx_catalog_proposals_normalized');
        $schema->getTable('catalog_proposals')->modifyColumn('moderation_status', [
            'type' => Type::getType(Types::TEXT),
            'length' => null,
        ]);
        $this->removeColumns($schema->getTable('catalog_proposals'), [
            'proposal_type',
            'normalized_key',
            'submitted_by_user_id',
            'revision',
            'duplicate_entity_id',
            'resolved_entity_type',
            'resolved_entity_id',
            'moderation_reason',
            'updated_at',
        ]);
    }

    /** @param list<string> $columns */
    private function removeColumns(Table $table, array $columns): void
    {
        foreach ($columns as $column) {
            $table->dropColumn($column);
        }
    }

    private function id(Table $table): void
    {
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->setPrimaryKey(['id']);
    }

    private function timestamps(Table $table): void
    {
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
    }
}
