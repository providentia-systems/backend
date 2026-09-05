<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Providentia\Identity\Application\ProfileMediaStore;

final class DbalProfileMediaStore implements ProfileMediaStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function homeProfile(string $homeId): ?array
    {
        $row = $this->connection->fetchAssociative(
            ('SELECT id, description, country_code AS countryCode, state_id AS '
                . 'stateId, city_id AS cityId, latitude, longitude, avatar_source AS '
                . 'avatarSource, avatar_revision AS avatarRevision, revision FROM homes '
                . 'WHERE id = ?'),
            [$homeId],
        );
        return $row === false
            ? null
            : $row;
    }

    public function updateHomeProfile(
        string $homeId,
        array $values,
        int $revision,
    ): bool {
        return $this->connection->update(
            'homes',
            [...$values, 'revision' => $revision + 1],
            ['id' => $homeId, 'revision' => $revision],
        ) === 1;
    }

    public function sharesHome(
        string $userId,
        string $otherUserId,
    ): bool {
        return $this->connection->fetchOne(
            ('SELECT a.home_id FROM home_memberships a INNER JOIN home_memberships b '
                . 'ON b.home_id = a.home_id
            WHERE a.user_id = ? AND b.user_id ='
                . ' ? AND a.status = \'active\' AND b.status = \'active\' LIMIT 1'),
            [$userId, $otherUserId],
        ) !== false;
    }

    public function image(string $scope, string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            ('SELECT image_bytes, content_sha256 FROM profile_images WHERE scope = ? '
                . 'AND subject_id = ?'),
            [$scope, $id],
        );
        if ($row === false) {
            return null;
        }
        $bytes = is_resource($row['image_bytes'])
            ? stream_get_contents($row['image_bytes'])
            : (string) $row['image_bytes'];
        if ($bytes === false) {
            throw new \RuntimeException('Stored profile image is unreadable.');
        }
        return [
            'bytes' => $bytes,
            'digest' => (string) $row['content_sha256'],
        ];
    }

    public function saveImage(
        string $scope,
        string $id,
        ?string $bytes,
        ?string $digest,
        int $revision,
        string $now,
    ): bool {
        $table = $scope === 'account'
            ? 'user_profiles'
            : 'homes';
        $key = $scope === 'account'
            ? 'user_id'
            : 'id';
        $changed = $this->connection->executeStatement(
            'UPDATE ' . $table
                . (' SET avatar_source = :source, avatar_revision = avatar_revision + 1, '
                . 'revision = revision + 1, updated_at = :now WHERE ') . $key
                . ' = :id AND avatar_revision = :revision',
            [
                'source' => $bytes === null
                    ? 'default'
                    : 'upload',
                'now' => $now,
                'id' => $id,
                'revision' => $revision,
            ],
        );
        if ($changed !== 1) {
            return false;
        }
        $this->connection->delete(
            'profile_images',
            ['scope' => $scope, 'subject_id' => $id],
        );
        if ($bytes !== null) {
            $this->connection->insert(
                'profile_images',
                [
                    'scope' => $scope,
                    'subject_id' => $id,
                    'image_bytes' => $bytes,
                    'content_sha256' => $digest,
                    'updated_at' => $now,
                ],
                ['image_bytes' => ParameterType::BINARY],
            );
        }
        return true;
    }
}
