<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Person-scoped BYOK provider profiles with owned custom endpoints';
    }

    public function up(Schema $schema): void
    {
        $profiles = $schema->getTable('ai_provider_profiles');
        $profiles->addColumn('owner_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $profiles->addColumn('endpoint', Types::STRING, ['length' => 300, 'notnull' => false]);
        $profiles->addIndex(['home_id', 'owner_user_id'], 'idx_ai_profile_home_owner');
        // The credential associated data moved to the v2 owner-scoped form, so
        // every pre-existing ciphertext can no longer be authenticated. This
        // pre-production system clears the encrypted fields instead of keeping
        // undecryptable secrets; owners re-enter the credential once. Existing
        // rows keep owner_user_id NULL, which is the home-shared scope.
        $this->addSql(
            'UPDATE ai_provider_profiles
             SET ciphertext = NULL, nonce = NULL, key_version = NULL, last_four = NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $profiles = $schema->getTable('ai_provider_profiles');
        $profiles->dropIndex('idx_ai_profile_home_owner');
        $profiles->dropColumn('owner_user_id');
        $profiles->dropColumn('endpoint');
    }
}
