<?php

declare(strict_types=1);

namespace Providentia\Geography\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Providentia\Geography\Application\CountryStore;

final class DbalCountryStore implements CountryStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function countries(bool $publishedOnly): array
    {
        return $this->connection->fetchAllAssociative(
            ('SELECT c.code, c.name, c.currency, c.timezones_json AS timezones, '
                . 'c.source_version AS sourceVersion,
                    s.published, '
                . 's.default_currency AS defaultCurrency, s.default_timezone AS '
                . 'defaultTimezone,
                    s.revision FROM reference_countries'
                . ' c LEFT JOIN country_settings s ON s.country_code = c.code
             '
                . 'WHERE c.active = 1') . ($publishedOnly
                ? ' AND s.published = 1'
                : '') . ' ORDER BY c.name',
        );
    }

    public function settings(string $code): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM country_settings WHERE country_code = ?',
            [$code],
        );
        return $row === false
            ? null
            : $row;
    }

    public function places(
        string $country,
        ?int $state,
        string $query,
        bool $cities,
        int $offset,
    ): array {
        $params = ['country' => $country];
        $sql = 'SELECT source_id AS id, name' . ($cities
            ? ', state_id AS stateId, latitude, longitude, timezone'
            : '') . ' FROM ' . ($cities
            ? 'reference_cities'
            : 'reference_states') . ' WHERE country_code = :country AND active = 1';
        if ($cities && $state !== null) {
            $sql .= ' AND state_id = :state';
            $params['state'] = $state;
        }
        if ($query !== '') {
            $sql .= ' AND LOWER(name) LIKE :query';
            $params['query'] = '%' . mb_strtolower(str_replace(['%', '_'], '', $query)) . '%';
        }
        return $this->connection->fetchAllAssociative(
            $sql . ' ORDER BY name, source_id LIMIT 100 OFFSET ' . max(0, $offset),
            $params,
        );
    }

    public function validPlace(
        string $country,
        ?int $state,
        ?int $city,
    ): bool {
        if (
            $state !== null && !$this->connection->fetchOne(
                ('SELECT source_id FROM reference_states WHERE source_id = ? AND '
                . 'country_code = ? AND active = 1'),
                [$state, $country],
            )
        ) {
            return false;
        }
        if ($city !== null) {
            $row = $this->connection->fetchAssociative(
                ('SELECT state_id FROM reference_cities WHERE source_id = ? AND '
                    . 'country_code = ? AND active = 1'),
                [$city, $country],
            );
            if ($row === false || $state !== null && (int) $row['state_id'] !== $state) {
                return false;
            }
        }
        return true;
    }

    public function saveSettings(
        string $code,
        array $values,
        int $revision,
    ): bool {
        $groups = [
            (string) $values['account_group_id'], (string) $values['invited_group_id'],
            (string) $values['home_group_id'],
        ];
        sort($groups);
        $lock = $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform
            ? '' : ' FOR UPDATE';
        foreach (array_unique($groups) as $group) {
            $this->connection->executeStatement('UPDATE access_groups SET id = id WHERE id = ?', [$group]);
            if ($this->connection->fetchOne('SELECT id FROM access_groups WHERE id = ?' . $lock, [$group]) === false) {
                return false;
            }
        }
        $policy = $this->lockPolicy((string) $values['policy_id']);
        if ($policy === null || $policy['status'] !== 'published') {
            return false;
        }
        return $this->connection->update(
            'country_settings',
            [...$values, 'revision' => $revision + 1],
            ['country_code' => $code, 'revision' => $revision],
        ) === 1;
    }

    public function policy(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM privacy_policies WHERE id = ?',
            [$id],
        );
        return $row === false
            ? null
            : $row;
    }

    public function policies(?string $country): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM privacy_policies' . ($country === null
                ? ''
                : ' WHERE country_code = :country OR country_code IS NULL')
                . ' ORDER BY updated_at DESC',
            $country === null
                ? []
                : ['country' => $country],
        );
    }

    public function savePolicy(
        string $id,
        array $values,
        int $revision,
    ): bool {
        if ($revision === 0) {
            $this->connection->insert(
                'privacy_policies',
                [...$values, 'id' => $id, 'revision' => 1],
            );
            return true;
        }
        return $this->connection->update(
            'privacy_policies',
            [...$values, 'revision' => $revision + 1],
            [
                'id' => $id,
                'revision' => $revision,
                'status' => 'draft',
            ],
        ) === 1;
    }

    public function deleteDraftPolicy(string $id, int $revision): ?array
    {
        $policy = $this->lockPolicy($id);
        if (
            $policy === null || $policy['status'] !== 'draft'
            || $policy['published_at'] !== null || (int) $policy['revision'] !== $revision
        ) {
            return null;
        }
        $deleted = $this->connection->executeStatement(
            "DELETE FROM privacy_policies WHERE id = :id AND revision = :revision
             AND status = 'draft' AND published_at IS NULL
             AND NOT EXISTS (SELECT 1 FROM country_settings c WHERE c.policy_id = privacy_policies.id)
             AND NOT EXISTS (SELECT 1 FROM policy_acceptances a WHERE a.policy_id = privacy_policies.id)",
            ['id' => $id, 'revision' => $revision],
        );

        return $deleted === 1 ? $policy : null;
    }

    /** @return array<string, mixed>|null */
    private function lockPolicy(string $id): ?array
    {
        $this->connection->executeStatement('UPDATE privacy_policies SET id = id WHERE id = ?', [$id]);
        $lock = $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform
            ? '' : ' FOR UPDATE';
        $row = $this->connection->fetchAssociative('SELECT * FROM privacy_policies WHERE id = ?' . $lock, [$id]);

        return $row === false ? null : $row;
    }

    public function acceptPolicy(string $userId, string $id, int $revision, string $country, string $now): void
    {
        $policy = $this->lockPolicy($id);
        if ($policy === null || $policy['status'] !== 'published' || (int) $policy['revision'] !== $revision) {
            throw new \Providentia\SharedKernel\Application\Problem(
                409,
                'Policy changed',
                'Read and accept the current published privacy notice.',
            );
        }
        if (
            $this->connection->fetchOne('SELECT user_id FROM policy_acceptances WHERE user_id = ? AND policy_id = ?', [
                $userId,
                $id,
            ])
        ) {
            return;
        }
        $this->connection->insert('policy_acceptances', [
            'user_id' => $userId,
            'policy_id' => $id,
            'policy_revision' => $revision,
            'country_code' => $country,
            'accepted_at' => $now,
        ]);
    }

    public function jobs(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM reference_update_jobs ORDER BY created_at DESC LIMIT 20',
        );
    }

    public function requestUpdate(
        string $id,
        string $userId,
        string $now,
    ): void {
        $this->connection->insert(
            'reference_update_jobs',
            [
                'id' => $id,
                'requested_by_user_id' => $userId,
                'status' => 'queued',
                'processed_count' => 0,
                'created_at' => $now,
            ],
        );
    }
}
