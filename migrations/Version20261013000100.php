<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Snapshot receipt purchase packaging and applied stock for immediate manual entry';
    }

    public function up(Schema $schema): void
    {
        $lines = $schema->getTable('receipt_lines');
        $lines->addColumn('stock_unit', Types::STRING, ['length' => 16, 'notnull' => false]);
        $lines->addColumn('selected_pack_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        foreach (['stock_quantity', 'stock_quantity_per_pack'] as $column) {
            $lines->addColumn(
                $column,
                $this->platform instanceof SQLitePlatform ? Types::STRING : Types::DECIMAL,
                ['length' => 32, 'precision' => 20, 'scale' => 8, 'notnull' => false],
            );
        }
        $prices = $schema->getTable('price_observations');
        $prices->addColumn('superseded_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $prices->dropIndex('uniq_price_observation_receipt_line');
        $prices->addIndex(['receipt_line_id'], 'idx_price_observation_receipt_line');
    }

    public function postUp(Schema $schema): void
    {
        // Existing committed imports already applied their entered quantity.
        // Preserve that historical interpretation rather than retroactively converting packs.
        $this->connection->executeStatement(
            'UPDATE receipt_lines SET stock_quantity = quantity, stock_quantity_per_pack = :factor,
             selected_pack_id = COALESCE(
                (SELECT po.product_pack_id FROM price_observations po
                 WHERE po.home_id = receipt_lines.home_id AND po.receipt_line_id = receipt_lines.id
                 ORDER BY po.created_at DESC, po.id DESC LIMIT 1),
                (SELECT hp.pack_id FROM home_products hp
                 WHERE hp.home_id = receipt_lines.home_id AND hp.id = receipt_lines.home_product_id)
             )
             WHERE approval_status = :approved AND stock_quantity IS NULL AND EXISTS
             (SELECT 1 FROM receipts r WHERE r.id = receipt_lines.receipt_id
              AND r.home_id = receipt_lines.home_id AND r.status = :committed)',
            ['factor' => '1', 'approved' => 'approved', 'committed' => 'committed'],
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->fetchOne(
                'SELECT rl.id FROM receipt_lines rl WHERE rl.stock_unit IS NOT NULL
                 OR (rl.stock_quantity_per_pack IS NOT NULL AND rl.stock_quantity_per_pack <> :legacy)
                 OR (rl.stock_quantity IS NOT NULL AND EXISTS
                    (SELECT 1 FROM receipts r WHERE r.id = rl.receipt_id AND r.home_id = rl.home_id
                     AND r.status = :draft)) LIMIT 1',
                ['legacy' => '1', 'draft' => 'draft'],
            ) !== false,
            'Purchase stock snapshots cannot be downgraded without risking duplicate or misinterpreted stock.',
        );
        $this->abortIf(
            $this->connection->fetchOne(
                'SELECT receipt_line_id FROM price_observations GROUP BY receipt_line_id HAVING COUNT(*) > 1 LIMIT 1',
            ) !== false,
            'Price correction history cannot be downgraded to a single-observation schema without losing evidence.',
        );
        $prices = $schema->getTable('price_observations');
        $prices->dropColumn('superseded_at');
        $prices->dropIndex('idx_price_observation_receipt_line');
        $prices->addUniqueIndex(['receipt_line_id'], 'uniq_price_observation_receipt_line');
        $lines = $schema->getTable('receipt_lines');
        foreach (['selected_pack_id', 'stock_quantity', 'stock_quantity_per_pack', 'stock_unit'] as $column) {
            $lines->dropColumn($column);
        }
    }
}
