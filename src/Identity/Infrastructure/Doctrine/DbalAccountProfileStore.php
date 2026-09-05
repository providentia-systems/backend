<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Providentia\Identity\Application\AccountProfileStore;
use Providentia\SharedKernel\Application\Problem;

final class DbalAccountProfileStore implements AccountProfileStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function profile(string $userId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM user_profiles WHERE user_id = ?',
            [$userId],
        );
        return $row === false
            ? []
            : $row;
    }

    public function update(
        string $userId,
        array $values,
        int $revision,
    ): bool {
        $values['revision'] = $revision + 1;
        return $this->connection->update(
            'user_profiles',
            $values,
            ['user_id' => $userId, 'revision' => $revision],
        ) === 1;
    }

    public function emails(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            ('SELECT e.id, e.email, e.verified_at, u.normalized_email FROM user_emails'
                . ' e
             INNER JOIN users u ON u.id = e.user_id WHERE e.user_id ='
                . ' ? ORDER BY e.verified_at, e.id'),
            [$userId],
        );
        return array_map(
            static fn(array $row): array => [
                'id' => (string) $row['id'],
                'email' => (string) $row['email'],
                'verifiedAt' => (string) $row['verified_at'],
                'primary' => $row['email'] === $row['normalized_email'],
            ],
            $rows,
        );
    }

    public function addEmail(
        string $id,
        string $userId,
        string $email,
        string $now,
    ): void {
        try {
            $this->connection->insert(
                'user_emails',
                [
                    'id' => $id,
                    'user_id' => $userId,
                    'email' => $email,
                    'normalized_email' => $email,
                    'verified_at' => $now,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            throw new Problem(
                409,
                'Email unavailable',
                'This verified email is already attached to an account.',
            );
        }
    }

    public function makePrimary(
        string $userId,
        string $emailId,
    ): bool {
        $row = $this->connection->fetchAssociative(
            'SELECT email FROM user_emails WHERE user_id = ? AND id = ?',
            [$userId, $emailId],
        );
        if ($row === false) {
            return false;
        }
        $this->connection->executeStatement(
            ('UPDATE users SET email = :email, normalized_email = :email, revision = '
                . 'revision + 1 WHERE id = :id'),
            ['email' => $row['email'], 'id' => $userId],
        );
        return true;
    }

    public function removeEmail(
        string $userId,
        string $emailId,
    ): bool {
        $this->connection->executeStatement(
            'UPDATE users SET id = id WHERE id = ?',
            [$userId],
        );
        $emails = $this->emails($userId);
        foreach ($emails as $email) {
            if ($email['id'] === $emailId && !$email['primary'] && count($emails) > 1) {
                $this->connection->executeStatement(
                    ('UPDATE user_profiles SET avatar_email_id = NULL, avatar_source = '
                        . '\'default\', revision = revision + 1
                     WHERE user_id = '
                        . '? AND avatar_email_id = ?'),
                    [$userId, $emailId],
                );
                return $this->connection->delete(
                    'user_emails',
                    ['id' => $emailId, 'user_id' => $userId],
                ) === 1;
            }
        }
        return false;
    }

    public function registerAdministrator(string $userId, string $now): void
    {
        if ($this->administratorStatus($userId) !== null) {
            return;
        }
        $this->connection->insert(
            'administrator_requests',
            [
                'user_id' => $userId,
                'status' => 'pending',
                'reviewer_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'revision' => 1,
            ],
        );
    }

    public function administratorStatus(string $userId): ?string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM administrator_requests WHERE user_id = ?',
            [$userId],
        );
        return $status === false
            ? null
            : (string) $status;
    }

    public function claimSystemOwner(string $userId, string $email): bool
    {
        return $this->connection->executeStatement(
            ('UPDATE system_owner_bootstrap SET user_id = :user WHERE singleton_id = 1'
                . ' AND email = :email AND user_id IS NULL'),
            ['user' => $userId, 'email' => $email],
        ) === 1;
    }

    public function isSystemOwner(string $userId): bool
    {
        return $this->connection->fetchOne(
            'SELECT user_id FROM system_owner_bootstrap WHERE singleton_id = 1',
        ) === $userId;
    }
}
