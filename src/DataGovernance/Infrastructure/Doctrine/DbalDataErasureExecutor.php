<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
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
        $account = $this->connection->fetchAssociative(
            'SELECT normalized_email FROM users WHERE id = :user',
            ['user' => $userId],
        );
        if ($account === false) {
            throw new \DomainException('The account is no longer available for erasure.');
        }
        $owners = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM home_memberships
             WHERE user_id = :user AND role = :role AND status = :status',
            ['user' => $userId, 'role' => 'owner', 'status' => 'active'],
        );
        if ($owners !== 0) {
            throw new \DomainException('Account ownership changed before erasure.');
        }
        $this->guardLastPlatformAdministrator($userId);

        $fingerprint = substr(hash('sha256', $userId), 0, 24);
        $originalEmail = (string) $account['normalized_email'];
        $pseudonymEmail = 'erased+' . $fingerprint . '@invalid';
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->retireLoginRequests($userId, $originalEmail, $pseudonymEmail, $fingerprint, $now);
        $this->retireHomeInvitations($userId, $originalEmail, $pseudonymEmail, $fingerprint, $now);
        $this->retirePlatformAdministratorGrants(
            $userId,
            $originalEmail,
            $pseudonymEmail,
            $now,
        );
        $this->connection->executeStatement(
            'UPDATE audit_events SET details = REPLACE(details, :email, :pseudonym)
             WHERE details LIKE :contains_email',
            [
                'email' => $originalEmail,
                'pseudonym' => $pseudonymEmail,
                'contains_email' => '%' . $originalEmail . '%',
            ],
        );

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
            'email' => $pseudonymEmail,
            'normalized_email' => $pseudonymEmail,
            'password_hash' => 'erased',
            'status' => 'erased',
            'email_verified_at' => null,
        ], ['id' => $userId]);
    }

    private function guardLastPlatformAdministrator(string $userId): void
    {
        // Use the same portable write-lock strategy as administrator revocation
        // so erasure cannot race another grant or revoke decision.
        $this->connection->executeStatement(
            'UPDATE user_platform_roles SET updated_at = updated_at
             WHERE role = :role AND revoked_at IS NULL',
            ['role' => 'platform_administrator'],
        );
        $subjectIsAdministrator = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_platform_roles r
             INNER JOIN users u ON u.id = r.user_id AND u.status = :active
             WHERE r.user_id = :user AND r.role = :role AND r.revoked_at IS NULL',
            [
                'active' => 'active',
                'user' => $userId,
                'role' => 'platform_administrator',
            ],
        ) === 1;
        if (! $subjectIsAdministrator) {
            return;
        }
        $activeAdministrators = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_platform_roles r
             INNER JOIN users u ON u.id = r.user_id AND u.status = :active
             WHERE r.role = :role AND r.revoked_at IS NULL',
            ['active' => 'active', 'role' => 'platform_administrator'],
        );
        if ($activeAdministrators <= 1) {
            throw new \DomainException(
                'Grant another active platform administrator before erasing this account.',
            );
        }
    }

    private function retireLoginRequests(
        string $userId,
        string $originalEmail,
        string $pseudonymEmail,
        string $fingerprint,
        string $now,
    ): void {
        $requests = $this->connection->fetchAllAssociative(
            'SELECT id, status, cancelled_at FROM auth_login_link_requests
             WHERE normalized_email = :email OR user_id = :user',
            ['email' => $originalEmail, 'user' => $userId],
        );
        foreach ($requests as $request) {
            $requestId = (string) $request['id'];
            $status = (string) $request['status'];
            $mustCancel = in_array(
                $status,
                ['pending', 'approved', 'approving', 'exchanging'],
                true,
            );
            $this->connection->update('auth_login_link_requests', [
                'request_hash' => $this->retiredHash($fingerprint, $requestId, 'request'),
                'normalized_email' => $pseudonymEmail,
                'installation_id' => $this->retiredInstallationId($fingerprint, $requestId),
                'device_name' => 'Erased device',
                'platform' => 'erased',
                'poll_challenge' => $this->retiredHash($fingerprint, $requestId, 'poll'),
                'code_challenge' => $this->retiredHash($fingerprint, $requestId, 'code'),
                'state_hash' => $this->retiredHash($fingerprint, $requestId, 'state'),
                'approval_token_hash' => null,
                'status' => $mustCancel ? 'cancelled' : $status,
                'user_id' => null,
                'onboarding_home_id' => null,
                'issued_session_id' => null,
                'cancelled_at' => $mustCancel ? $now : $request['cancelled_at'],
                'updated_at' => $now,
            ], ['id' => $requestId]);
        }
    }

    private function retireHomeInvitations(
        string $userId,
        string $originalEmail,
        string $pseudonymEmail,
        string $fingerprint,
        string $now,
    ): void {
        $invitations = $this->connection->fetchAllAssociative(
            'SELECT id, status, revoked_at FROM home_invitations
             WHERE normalized_email = :email OR accepted_by_user_id = :user',
            ['email' => $originalEmail, 'user' => $userId],
        );
        foreach ($invitations as $invitation) {
            $invitationId = (string) $invitation['id'];
            $pending = (string) $invitation['status'] === 'pending';
            $this->connection->update('home_invitations', [
                'normalized_email' => $pseudonymEmail,
                'token_hash' => $this->retiredHash($fingerprint, $invitationId, 'invitation'),
                'status' => $pending ? 'revoked' : (string) $invitation['status'],
                'accepted_by_user_id' => null,
                'revoked_at' => $pending ? $now : $invitation['revoked_at'],
            ], ['id' => $invitationId]);
        }
    }

    private function retirePlatformAdministratorGrants(
        string $userId,
        string $originalEmail,
        string $pseudonymEmail,
        string $now,
    ): void {
        $grants = $this->connection->fetchAllAssociative(
            'SELECT id, revision, granted_by_user_id FROM platform_administrator_email_grants
             WHERE normalized_email = :email OR accepted_by_user_id = :user',
            ['email' => $originalEmail, 'user' => $userId],
        );
        foreach ($grants as $grant) {
            $this->connection->update('platform_administrator_email_grants', [
                'normalized_email' => $pseudonymEmail,
                'status' => 'revoked',
                'revision' => (int) $grant['revision'] + 1,
                'granted_by_user_id' => $grant['granted_by_user_id'] === $userId
                    ? null
                    : $grant['granted_by_user_id'],
                'accepted_by_user_id' => null,
                'revoked_at' => $now,
                'updated_at' => $now,
            ], ['id' => (string) $grant['id']]);
        }
        $this->connection->update(
            'platform_administrator_email_grants',
            ['granted_by_user_id' => null],
            ['granted_by_user_id' => $userId],
        );
    }

    private function retiredHash(string $fingerprint, string $recordId, string $purpose): string
    {
        return hash('sha256', 'erased:' . $fingerprint . ':' . $recordId . ':' . $purpose);
    }

    private function retiredInstallationId(string $fingerprint, string $recordId): string
    {
        $hex = substr(hash('sha256', 'erased:' . $fingerprint . ':' . $recordId), 0, 32);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
