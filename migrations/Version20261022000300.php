<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve auditable count-interval evidence used by consumption estimates';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('consumption_estimates')->addColumn('evidence_json', Types::TEXT, ['notnull' => false]);
        $schema->getTable('shopping_suggestion_runs')->addColumn(
            'retained_decisions_json',
            Types::TEXT,
            ['notnull' => false],
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->fetchOne('SELECT id FROM consumption_estimates WHERE evidence_json IS NOT NULL LIMIT 1')
                !== false,
            'Consumption evidence cannot be discarded by downgrading this migration.',
        );
        $schema->getTable('consumption_estimates')->dropColumn('evidence_json');
        $schema->getTable('shopping_suggestion_runs')->dropColumn('retained_decisions_json');
    }
}
