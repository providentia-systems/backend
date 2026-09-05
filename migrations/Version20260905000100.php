<?php

declare(strict_types=1);

namespace Providentia\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Providentia\Access\Domain\FeatureCatalog;

final class Version20260905000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pre-release group authorization, OTP identities, profiles, geography and policy records';
    }

    public function up(Schema $schema): void
    {
        $groups = $schema->createTable('access_groups');
        $this->id($groups);
        $groups->addColumn('scope', Types::STRING, ['length' => 16]);
        $groups->addColumn('name', Types::STRING, ['length' => 120]);
        $groups->addColumn('description', Types::TEXT);
        foreach (['features_json', 'limits_json', 'delegable_json', 'role_permissions_json'] as $column) {
            $groups->addColumn($column, Types::TEXT);
        }
        $groups->addColumn('protected', Types::BOOLEAN, ['default' => false]);
        $this->revision($groups);
        $assignments = $schema->createTable('access_assignments');
        $assignments->addColumn('scope', Types::STRING, ['length' => 16]);
        $assignments->addColumn('subject_id', Types::STRING, ['length' => 36]);
        $assignments->addColumn('group_id', Types::STRING, ['length' => 36]);
        $assignments->setPrimaryKey(['scope', 'subject_id']);
        $assignments->addForeignKeyConstraint('access_groups', ['group_id'], ['id']);
        $this->revision($assignments);
        $overrides = $schema->createTable('member_permission_overrides');
        $overrides->addColumn('home_id', Types::STRING, ['length' => 36]);
        $overrides->addColumn('user_id', Types::STRING, ['length' => 36]);
        $overrides->addColumn('permissions_json', Types::TEXT);
        $overrides->setPrimaryKey(['home_id', 'user_id']);
        $overrides->addForeignKeyConstraint('home_memberships', ['home_id', 'user_id'], ['home_id', 'user_id'], ['onDelete' => 'CASCADE']);
        $this->revision($overrides);
        $audit = $schema->createTable('platform_audit_events');
        $this->id($audit);
        $audit->addColumn('actor_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $audit->addColumn('action', Types::STRING, ['length' => 80]);
        $audit->addColumn('scope', Types::STRING, ['length' => 32]);
        $audit->addColumn('subject_id', Types::STRING, ['length' => 80]);
        $audit->addColumn('details_json', Types::TEXT);
        $audit->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $audit->addIndex(['created_at', 'id'], 'idx_platform_audit_time');

        $emails = $schema->createTable('user_emails');
        $this->id($emails);
        $emails->addColumn('user_id', Types::STRING, ['length' => 36]);
        $emails->addColumn('email', Types::STRING, ['length' => 254]);
        $emails->addColumn('normalized_email', Types::STRING, ['length' => 254]);
        $emails->addColumn('verified_at', Types::DATETIME_IMMUTABLE);
        $emails->addUniqueIndex(['normalized_email'], 'uniq_user_email_address');
        $emails->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $codes = $schema->createTable('email_code_challenges');
        $this->id($codes);
        $codes->addColumn('email', Types::STRING, ['length' => 254]);
        $codes->addColumn('user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $codes->addColumn('purpose', Types::STRING, ['length' => 32]);
        $codes->addColumn('code_hash', Types::STRING, ['length' => 64]);
        $codes->addColumn('binding_hash', Types::STRING, ['length' => 64]);
        $codes->addColumn('context_json', Types::TEXT);
        $codes->addColumn('attempts', Types::INTEGER, ['default' => 0]);
        $codes->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $codes->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $codes->addColumn('consumed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $codes->addIndex(['email', 'created_at'], 'idx_email_code_resend');
        $codes->addIndex(['expires_at'], 'idx_email_code_expiry');
        $administrators = $schema->createTable('administrator_requests');
        $administrators->addColumn('user_id', Types::STRING, ['length' => 36]);
        $administrators->setPrimaryKey(['user_id']);
        $administrators->addColumn('status', Types::STRING, ['length' => 16]);
        $administrators->addColumn('reviewer_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $administrators->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $administrators->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $this->revision($administrators);
        $bootstrap = $schema->createTable('system_owner_bootstrap');
        $bootstrap->addColumn('singleton_id', Types::INTEGER);
        $bootstrap->setPrimaryKey(['singleton_id']);
        $bootstrap->addColumn('email', Types::STRING, ['length' => 254]);
        $bootstrap->addColumn('user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $bootstrap->addColumn('created_at', Types::DATETIME_IMMUTABLE);

        $countries = $schema->createTable('reference_countries');
        $countries->addColumn('code', Types::STRING, ['length' => 2]);
        $countries->setPrimaryKey(['code']);
        $countries->addColumn('source_id', Types::INTEGER);
        $countries->addColumn('name', Types::STRING, ['length' => 120]);
        $countries->addColumn('currency', Types::STRING, ['length' => 3]);
        $countries->addColumn('timezones_json', Types::TEXT);
        $countries->addColumn('source_version', Types::STRING, ['length' => 100]);
        $countries->addColumn('active', Types::BOOLEAN, ['default' => true]);
        foreach (['reference_states', 'reference_cities'] as $tableName) {
            $table = $schema->createTable($tableName);
            $table->addColumn('source_id', Types::INTEGER);
            $table->setPrimaryKey(['source_id']);
            $table->addColumn('country_code', Types::STRING, ['length' => 2]);
            $table->addColumn('name', Types::STRING, ['length' => 200]);
            $table->addColumn('source_version', Types::STRING, ['length' => 100]);
            $table->addColumn('active', Types::BOOLEAN, ['default' => true]);
            $table->addIndex(['country_code', 'name'], 'idx_' . $tableName . '_country');
            if ($tableName === 'reference_cities') {
                $table->addColumn('state_id', Types::INTEGER, ['notnull' => false]);
                $table->addColumn('latitude', Types::STRING, ['length' => 32, 'notnull' => false]);
                $table->addColumn('longitude', Types::STRING, ['length' => 32, 'notnull' => false]);
                $table->addColumn('timezone', Types::STRING, ['length' => 64, 'notnull' => false]);
                $table->addIndex(['state_id', 'name'], 'idx_reference_city_state');
            }
        }
        $settings = $schema->createTable('country_settings');
        $settings->addColumn('country_code', Types::STRING, ['length' => 2]);
        $settings->setPrimaryKey(['country_code']);
        $settings->addColumn('published', Types::BOOLEAN, ['default' => false]);
        foreach (['account_group_id', 'invited_group_id', 'home_group_id'] as $column) {
            $settings->addColumn($column, Types::STRING, ['length' => 36]);
            $settings->addForeignKeyConstraint('access_groups', [$column], ['id']);
        }
        $settings->addColumn('default_currency', Types::STRING, ['length' => 3]);
        $settings->addColumn('default_timezone', Types::STRING, ['length' => 64]);
        $settings->addColumn('policy_id', Types::STRING, ['length' => 36]);
        $this->revision($settings);
        $policies = $schema->createTable('privacy_policies');
        $this->id($policies);
        $policies->addColumn('country_code', Types::STRING, ['length' => 2, 'notnull' => false]);
        $policies->addColumn('title', Types::STRING, ['length' => 200]);
        $policies->addColumn('body', Types::TEXT);
        $policies->addColumn('status', Types::STRING, ['length' => 16]);
        $policies->addColumn('published_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->revision($policies);
        $acceptances = $schema->createTable('policy_acceptances');
        $acceptances->addColumn('user_id', Types::STRING, ['length' => 36]);
        $acceptances->addColumn('policy_id', Types::STRING, ['length' => 36]);
        $acceptances->addColumn('policy_revision', Types::INTEGER);
        $acceptances->addColumn('country_code', Types::STRING, ['length' => 2]);
        $acceptances->addColumn('accepted_at', Types::DATETIME_IMMUTABLE);
        $acceptances->setPrimaryKey(['user_id', 'policy_id']);
        $acceptances->addForeignKeyConstraint('privacy_policies', ['policy_id'], ['id']);
        $acceptances->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $jobs = $schema->createTable('reference_update_jobs');
        $this->id($jobs);
        $jobs->addColumn('requested_by_user_id', Types::STRING, ['length' => 36]);
        $jobs->addColumn('status', Types::STRING, ['length' => 16]);
        $jobs->addColumn('source_version', Types::STRING, ['length' => 100, 'notnull' => false]);
        $jobs->addColumn('processed_count', Types::INTEGER, ['default' => 0]);
        $jobs->addColumn('safe_message', Types::STRING, ['length' => 300, 'notnull' => false]);
        $jobs->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $jobs->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $jobs->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

        $profiles = $schema->getTable('user_profiles');
        $profiles->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $profiles->addColumn('onboarding_completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        foreach ([$profiles, $schema->getTable('homes')] as $table) {
            $table->addColumn('country_code', Types::STRING, ['length' => 2, 'notnull' => false]);
            $table->addColumn('state_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('city_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('avatar_source', Types::STRING, ['length' => 16, 'default' => 'default']);
            $table->addColumn('avatar_revision', Types::INTEGER, ['default' => 0]);
        }
        $profiles->addColumn('avatar_email_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $homes = $schema->getTable('homes');
        $homes->addColumn('description', Types::TEXT, ['notnull' => false]);
        $homes->addColumn('latitude', Types::STRING, ['length' => 32, 'notnull' => false]);
        $homes->addColumn('longitude', Types::STRING, ['length' => 32, 'notnull' => false]);
        $images = $schema->createTable('profile_images');
        $images->addColumn('scope', Types::STRING, ['length' => 16]);
        $images->addColumn('subject_id', Types::STRING, ['length' => 36]);
        $images->setPrimaryKey(['scope', 'subject_id']);
        $images->addColumn('image_bytes', Types::BLOB, ['length' => 5242880]);
        $images->addColumn('content_sha256', Types::STRING, ['length' => 64]);
        $images->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
    }

    public function postUp(Schema $schema): void
    {
        $at = gmdate('Y-m-d H:i:s');
        foreach (FeatureCatalog::defaults() as $group) {
            $this->connection->insert('access_groups', [
                'id' => $group['id'], 'scope' => $group['scope'], 'name' => $group['name'],
                'description' => $group['description'], 'revision' => 1,
                'features_json' => json_encode($group['features'], JSON_THROW_ON_ERROR),
                'limits_json' => json_encode($group['limits'], JSON_THROW_ON_ERROR),
                'delegable_json' => json_encode($group['delegablePermissions'], JSON_THROW_ON_ERROR),
                'role_permissions_json' => json_encode($group['rolePermissions'], JSON_THROW_ON_ERROR),
                'protected' => (int) $group['protected'], 'updated_at' => $at,
            ]);
        }
        $policyId = 'a1000000-0000-4000-8000-000000000005';
        $body = (string) file_get_contents(dirname(__DIR__) . '/resources/policies/default-privacy.txt');
        $this->connection->insert('privacy_policies', [
            'id' => $policyId, 'country_code' => null, 'title' => 'Providentia privacy notice',
            'body' => $body, 'status' => 'published', 'revision' => 1, 'published_at' => $at, 'updated_at' => $at,
        ]);
        /** @var list<array{id: int, code: string, name: string, currency: string, timezones: list<string>}> $countries */
        $countries = json_decode((string) file_get_contents(dirname(__DIR__) . '/resources/reference/countries.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($countries as $country) {
            $this->connection->insert('reference_countries', [
                'code' => $country['code'], 'source_id' => $country['id'], 'name' => $country['name'],
                'currency' => $country['currency'], 'timezones_json' => json_encode($country['timezones'], JSON_THROW_ON_ERROR),
                'source_version' => 'v3.2-export.7', 'active' => 1,
            ]);
            $this->connection->insert('country_settings', [
                'country_code' => $country['code'], 'published' => (int) ($country['code'] === 'NA'),
                'account_group_id' => FeatureCatalog::STARTER_ACCOUNT,
                'invited_group_id' => FeatureCatalog::INVITED_ACCOUNT, 'home_group_id' => FeatureCatalog::STARTER_HOME,
                'default_currency' => $country['currency'], 'default_timezone' => $country['timezones'][0] ?? 'UTC',
                'policy_id' => $policyId, 'revision' => 1, 'updated_at' => $at,
            ]);
        }
        $stream = fopen(dirname(__DIR__) . '/resources/reference/states.csv', 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Bundled region reference is missing.');
        }
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($header === false || count($row) !== count($header)) {
                    throw new \RuntimeException('Invalid bundled region reference.');
                }
                $state = array_combine($header, $row);
                $this->connection->insert('reference_states', [
                    'source_id' => (int) $state['id'], 'country_code' => $state['country_code'], 'name' => $state['name'],
                    'source_version' => 'v3.2-export.7', 'active' => 1,
                ]);
            }
        } finally {
            fclose($stream);
        }

    }

    public function down(Schema $schema): void
    {
        foreach (['user_profiles', 'homes'] as $name) {
            foreach (['country_code', 'state_id', 'city_id', 'avatar_source', 'avatar_revision'] as $column) {
                $schema->getTable($name)->dropColumn($column);
            }
        }
        foreach (['revision', 'onboarding_completed_at', 'avatar_email_id'] as $column) {
            $schema->getTable('user_profiles')->dropColumn($column);
        }
        foreach (['description', 'latitude', 'longitude'] as $column) {
            $schema->getTable('homes')->dropColumn($column);
        }
        foreach ([
            'profile_images', 'reference_update_jobs', 'policy_acceptances', 'country_settings', 'privacy_policies',
            'reference_cities', 'reference_states', 'reference_countries', 'system_owner_bootstrap',
            'administrator_requests', 'email_code_challenges', 'user_emails', 'platform_audit_events',
            'member_permission_overrides', 'access_assignments', 'access_groups',
        ] as $name) {
            $schema->dropTable($name);
        }
    }

    private function id(Table $table): void
    {
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->setPrimaryKey(['id']);
    }

    private function revision(Table $table): void
    {
        $table->addColumn('revision', Types::INTEGER, ['default' => 1]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
    }
}
