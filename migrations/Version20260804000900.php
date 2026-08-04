<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Passwordless authentication and encrypted durable notification delivery';
    }

    public function up(Schema $schema): void
    {
        $outbox = $schema->createTable('notification_outbox');
        $outbox->addColumn('id', Types::STRING, ['length' => 36]);
        $outbox->addColumn('template', Types::STRING, ['length' => 64]);
        $outbox->addColumn('recipient', Types::STRING, ['length' => 254]);
        $outbox->addColumn('encrypted_payload', Types::TEXT);
        $outbox->addColumn('nonce', Types::STRING, ['length' => 64]);
        $outbox->addColumn('key_version', Types::INTEGER);
        $outbox->addColumn('status', Types::STRING, ['length' => 24]);
        $outbox->addColumn('available_at', Types::DATETIME_IMMUTABLE);
        $outbox->addColumn('lease_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $outbox->addColumn('attempts', Types::INTEGER, ['default' => 0]);
        $outbox->addColumn('last_error', Types::STRING, ['length' => 191, 'notnull' => false]);
        $outbox->addColumn('sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $outbox->addColumn('dead_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $outbox->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $outbox->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $outbox->setPrimaryKey(['id']);
        $outbox->addIndex(['status', 'available_at', 'lease_until'], 'idx_notification_delivery');
        $outbox->addIndex(['recipient', 'template', 'created_at'], 'idx_notification_recipient');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('notification_outbox');
    }
}
