<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Providentia\DataGovernance\Application\DataErasureExecutor;

final readonly class DbalDataErasureExecutor implements DataErasureExecutor
{
    public function __construct(private Connection $connection)
    {
    }

    public function erase(array $request): void
    {
        $this->connection->transactional(function () use ($request): void {
            if ((string) $request['scopeType'] === 'home') {
                $this->eraseHome((string) $request['homeId'], (string) $request['requestedByUserId']);
                return;
            }
            $this->eraseAccount((string) $request['subjectUserId']);
        });
    }

    private function eraseHome(string $homeId, string $actorUserId): void
    {
        $owner = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM home_memberships
             WHERE home_id = :home AND user_id = :user AND role = :role AND status = :status',
            ['home' => $homeId, 'user' => $actorUserId, 'role' => 'owner', 'status' => 'active'],
        );
        if ((int) $owner !== 1) {
            throw new \DomainException('Home ownership changed before erasure.');
        }
        $this->connection->update('audit_events', ['home_id' => null, 'actor_user_id' => null], ['home_id' => $homeId]);
        $this->connection->update(
            'catalog_consent_receipts',
            ['recorded_by_user_id' => null],
            ['home_id' => $homeId],
        );
        $this->connection->update(
            'catalog_contributions',
            ['submitted_by_user_id' => null],
            ['home_id' => $homeId],
        );
        if ($this->connection->delete('homes', ['id' => $homeId]) !== 1) {
            throw new \DomainException('The home is no longer available for erasure.');
        }
    }

    private function eraseAccount(string $userId): void
    {
        $owners = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM home_memberships
             WHERE user_id = :user AND role = :role AND status = :status',
            ['user' => $userId, 'role' => 'owner', 'status' => 'active'],
        );
        if ($owners !== 0) {
            throw new \DomainException('Account ownership changed before erasure.');
        }
        $fingerprint = substr(hash('sha256', $userId), 0, 24);
        $this->connection->delete('auth_sessions', ['user_id' => $userId]);
        $this->connection->delete('auth_one_time_tokens', ['user_id' => $userId]);
        $this->connection->delete('devices', ['user_id' => $userId]);
        $this->connection->delete('user_platform_roles', ['user_id' => $userId]);
        $this->connection->delete('home_memberships', ['user_id' => $userId]);
        $this->connection->update('audit_events', ['actor_user_id' => null], ['actor_user_id' => $userId]);
        $this->connection->update(
            'catalog_contributions',
            ['submitted_by_user_id' => null],
            ['submitted_by_user_id' => $userId],
        );
        $this->connection->update(
            'catalog_contributions',
            ['reviewed_by_user_id' => null],
            ['reviewed_by_user_id' => $userId],
        );
        $this->connection->update(
            'catalog_consent_receipts',
            ['recorded_by_user_id' => null],
            ['recorded_by_user_id' => $userId],
        );
        $this->connection->update(
            'catalog_contribution_consents',
            ['updated_by_user_id' => null],
            ['updated_by_user_id' => $userId],
        );
        $this->connection->update(
            'data_governance_requests',
            ['subject_user_id' => null],
            ['subject_user_id' => $userId],
        );
        $this->connection->update(
            'data_governance_requests',
            ['requested_by_user_id' => null],
            ['requested_by_user_id' => $userId],
        );
        $this->connection->update('user_profiles', ['display_name' => 'Erased account'], ['user_id' => $userId]);
        $this->connection->update('users', [
            'email' => 'erased+' . $fingerprint . '@invalid',
            'normalized_email' => 'erased+' . $fingerprint . '@invalid',
            'password_hash' => 'erased',
            'status' => 'erased',
            'email_verified_at' => null,
        ], ['id' => $userId]);
    }
}
