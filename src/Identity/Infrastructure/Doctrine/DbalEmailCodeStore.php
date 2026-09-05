<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Providentia\Identity\Application\EmailCodeStore;

final class DbalEmailCodeStore implements EmailCodeStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issue(array $challenge): void
    {
        // The email rate-limit bucket serializes issuance before this transaction.
        $this->connection->executeStatement(
            'UPDATE email_code_challenges SET consumed_at = :now
             WHERE email = :email AND purpose = :purpose AND consumed_at IS NULL',
            ['now' => $challenge['created_at'], 'email' => $challenge['email'], 'purpose' => $challenge['purpose']],
        );
        $this->connection->insert('email_code_challenges', $challenge);
    }

    public function consume(string $id, string $codeHash, string $bindingHash, string $purpose, string $now): ?array
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Code verification must precede the application transaction.');
        }

        return $this->connection->transactional(function () use ($id, $codeHash, $bindingHash, $purpose, $now): ?array {
            $changed = $this->connection->executeStatement(
                'UPDATE email_code_challenges SET attempts = attempts + 1
                 WHERE id = :id AND purpose = :purpose AND binding_hash = :binding
                   AND attempts < 5 AND consumed_at IS NULL AND expires_at > :now',
                ['id' => $id, 'purpose' => $purpose, 'binding' => $bindingHash, 'now' => $now],
            );
            if ($changed !== 1) {
                return null;
            }
            $row = $this->connection->fetchAssociative('SELECT * FROM email_code_challenges WHERE id = ?', [$id]);
            if ($row === false || ! hash_equals((string) $row['code_hash'], $codeHash)) {
                return null;
            }
            $this->connection->update('email_code_challenges', ['consumed_at' => $now], ['id' => $id]);

            return $row;
        });
    }

    public function purge(string $before): int
    {
        return $this->connection->executeStatement('DELETE FROM email_code_challenges WHERE expires_at < ?', [$before]);
    }
}
