<?php

declare(strict_types=1);

namespace Providentia\Home\Application;

use DateInterval;
use DateTimeZone;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class HomeService
{
    public function __construct(
        private readonly HomeStore $homes,
        private readonly HomeAuthorization $authorization,
        private readonly IdentityStore $identities,
        private readonly CredentialHasher $hasher,
        private readonly AccountNotificationSender $notifications,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly SecureTokenGenerator $tokens,
        private readonly ?AuthenticationService $authentication = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(
        AuthenticatedIdentity $identity,
        string $name,
        string $locale,
        string $currency,
        string $timezone,
    ): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new Problem(422, 'Validation failed', 'Home name must contain 1 to 120 characters.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new Problem(422, 'Validation failed', 'Currency must be a three-letter ISO code.');
        }
        $id = $this->ids->generate();
        $now = $this->clock->now();
        $this->transactions->transactional(function () use (
            $id,
            $identity,
            $name,
            $locale,
            $currency,
            $timezone,
            $now,
        ): void {
            $this->homes->createHome(
                $id,
                $identity->userId,
                $name,
                $locale,
                $currency,
                $timezone,
                $now,
            );
            $this->audit($identity, 'home.created', 'home', $id, $id, [], $now);
        });

        $home = $this->homes->findHome($id);
        if ($home === null) {
            throw new \RuntimeException('The newly created home could not be loaded.');
        }
        $home['role'] = HomeAuthorization::OWNER;

        return $home;
    }

    /** @return array<string, mixed> */
    public function update(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $name,
        string $locale,
        string $currency,
        string $timezone,
        int $expectedRevision,
    ): array {
        $name = trim($name);
        $locale = trim($locale);
        $currency = strtoupper(trim($currency));
        $timezone = trim($timezone);
        if ($expectedRevision < 1) {
            throw new Problem(422, 'Validation failed', 'A positive expected revision is required.');
        }
        if ($name === '' || mb_strlen($name) > 120) {
            throw new Problem(422, 'Validation failed', 'Home name must contain 1 to 120 characters.');
        }
        if ($locale === '' || mb_strlen($locale) > 16) {
            throw new Problem(422, 'Validation failed', 'Home locale must contain 1 to 16 characters.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new Problem(422, 'Validation failed', 'Currency must be a three-letter ISO code.');
        }
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new Problem(422, 'Validation failed', 'A recognized IANA timezone is required.');
        }

        $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $name,
            $locale,
            $currency,
            $timezone,
            $expectedRevision,
        ): void {
            $this->authorization->requirePermission($identity, $homeId, HomePermission::HOME_MANAGE);
            $now = $this->clock->now();
            if (
                ! $this->homes->updateHome(
                    $homeId,
                    $name,
                    $locale,
                    $currency,
                    $timezone,
                    $expectedRevision,
                    $now,
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The home changed since it was read.');
            }
            $this->audit(
                $identity,
                'home.settings.updated',
                'home',
                $homeId,
                $homeId,
                [],
                $now,
            );
        });

        return $this->get($identity, $homeId);
    }

    /** @return list<array<string, mixed>> */
    public function list(AuthenticatedIdentity $identity): array
    {
        return $this->homes->listForUser($identity->userId);
    }

    /** @return array<string, mixed> */
    public function get(AuthenticatedIdentity $identity, string $homeId): array
    {
        $membership = $this->authorization->requirePermission($identity, $homeId, HomePermission::HOME_READ);
        $home = $this->homes->findHome($homeId);
        if ($home === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }

        $home['role'] = (string) $membership['role'];

        return $home;
    }

    /** @return list<array<string, mixed>> */
    public function memberships(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::MEMBERS_READ);

        return $this->homes->memberships($homeId);
    }

    /** @return list<array{role: string, revision: int, permissions: list<string>, configurable: bool}> */
    public function permissionPolicies(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requirePermission($identity, $homeId, HomePermission::HOME_READ);
        /** @var array<string, array{role: string, revision: int, permissions: list<string>}> $persisted */
        $persisted = [];
        foreach ($this->homes->permissionPolicies($homeId) as $policy) {
            $persisted[$policy['role']] = $policy;
        }
        $result = [];
        foreach (
            [
                HomeAuthorization::OWNER,
                HomeAuthorization::MANAGER,
                HomeAuthorization::MEMBER,
                HomeAuthorization::VIEWER,
            ] as $role
        ) {
            $policy = $persisted[$role] ?? [
                'role' => $role,
                'revision' => 0,
                'permissions' => HomePermission::defaultsForRole($role),
            ];
            $result[] = [
                ...$policy,
                'configurable' => $role !== HomeAuthorization::OWNER,
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $permissions
     * @return array{role: string, revision: int, permissions: list<string>}
     */
    public function configureRolePermissions(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $role,
        array $permissions,
        int $expectedRevision,
    ): array {
        $actor = $this->authorization->requirePermission(
            $identity,
            $homeId,
            HomePermission::PERMISSIONS_MANAGE,
        );
        $role = strtolower(trim($role));
        if (
            ! in_array(
                $role,
                [HomeAuthorization::MANAGER, HomeAuthorization::MEMBER, HomeAuthorization::VIEWER],
                true,
            )
            || $expectedRevision < 0
        ) {
            throw new Problem(422, 'Validation failed', 'The role policy or expected revision is invalid.');
        }
        $permissions = array_values(array_unique(array_map('trim', $permissions)));
        sort($permissions);
        foreach ($permissions as $permission) {
            if (! HomePermission::isKnown($permission)) {
                throw new Problem(422, 'Validation failed', 'Unknown home permission: ' . $permission);
            }
            if ((string) $actor['role'] !== HomeAuthorization::OWNER) {
                $this->authorization->requirePermission($identity, $homeId, $permission);
            }
        }

        $now = $this->clock->now();
        $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $role,
            $permissions,
            $expectedRevision,
            $now,
        ): void {
            if (
                ! $this->homes->replaceRolePermissions(
                    $homeId,
                    $role,
                    $permissions,
                    $expectedRevision,
                    $identity->userId,
                    $now,
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The role permission policy changed.');
            }
            $this->audit(
                $identity,
                'home.permissions.changed',
                'home_role_policy',
                $role,
                $homeId,
                ['permissions' => $permissions],
                $now,
            );
        });

        return [
            'role' => $role,
            'revision' => $expectedRevision + 1,
            'permissions' => $permissions,
        ];
    }

    /** @return array{invitationId: string, invitationToken: string, expiresAt: string, revision: int} */
    public function invite(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $email,
        string $role,
    ): array {
        $role = strtolower(trim($role));
        $allowed = [HomeAuthorization::MANAGER, HomeAuthorization::MEMBER, HomeAuthorization::VIEWER];
        if (! in_array($role, $allowed, true)) {
            throw new Problem(422, 'Validation failed', 'Invitation role is invalid.');
        }
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new Problem(422, 'Validation failed', 'A valid invitation email is required.');
        }

        $id = $this->ids->generate();
        $token = $this->tokens->generate();
        $now = $this->clock->now();
        $expires = $now->add(new DateInterval('P7D'));
        $this->transactions->transactional(function () use (
            $id,
            $homeId,
            $identity,
            $email,
            $role,
            $token,
            $expires,
            $now,
        ): void {
            $actor = $this->authorization->requirePermission(
                $identity,
                $homeId,
                HomePermission::MEMBERS_INVITE,
            );
            if (
                (string) $actor['role'] !== HomeAuthorization::OWNER
                && $role === HomeAuthorization::MANAGER
            ) {
                throw new Problem(403, 'Forbidden', 'Only an owner can create a manager membership.');
            }
            $this->homes->createInvitation(
                $id,
                $homeId,
                $identity->userId,
                $email,
                $role,
                $this->hasher->hashToken($token),
                $expires,
                $now,
            );
            $this->audit(
                $identity,
                'home.invitation.created',
                'home_invitation',
                $id,
                $homeId,
                ['role' => $role],
                $now,
            );
            $home = $this->homes->findHome($homeId);
            $this->notifications->sendHomeInvitation(
                $email,
                (string) ($home['name'] ?? 'Providentia home'),
                $role,
            );
        });

        return [
            'invitationId' => $id,
            'invitationToken' => $token,
            'expiresAt' => $expires->format(DATE_ATOM),
            'revision' => 1,
        ];
    }

    /** @return array<string, mixed> */
    public function acceptInvitation(AuthenticatedIdentity $identity, string $token): array
    {
        $user = $this->identities->findUserById($identity->userId);
        if ($user === null) {
            throw new Problem(401, 'Authentication required', 'The account is unavailable.');
        }
        $result = $this->transactions->transactional(function () use ($token, $identity, $user): array {
            $result = $this->homes->acceptInvitation(
                $this->hasher->hashToken($token),
                $identity->userId,
                (string) $user['normalized_email'],
                $this->clock->now(),
            );
            if ($result === null) {
                throw new Problem(
                    422,
                    'Invalid invitation',
                    'The invitation is invalid, expired, used, or not addressed to you.',
                );
            }
            $this->audit(
                $identity,
                'home.invitation.accepted',
                'home_invitation',
                (string) $result['invitationId'],
                (string) $result['homeId'],
                [],
                $this->clock->now(),
            );

            return $result;
        });

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function pendingInvitations(AuthenticatedIdentity $identity): array
    {
        $user = $this->identities->findUserById($identity->userId);
        if ($user === null || $user['email_verified_at'] === null) {
            return [];
        }

        return $this->homes->pendingInvitationsForEmail(
            (string) $user['normalized_email'],
            $this->clock->now(),
        );
    }

    /** @return array<string, mixed> */
    public function acceptInvitationById(
        AuthenticatedIdentity $identity,
        string $invitationId,
        int $expectedRevision,
    ): array {
        if ($expectedRevision < 1) {
            throw new Problem(422, 'Validation failed', 'A positive expected revision is required.');
        }
        $user = $this->identities->findUserById($identity->userId);
        if ($user === null || $user['email_verified_at'] === null) {
            throw new Problem(401, 'Authentication required', 'A verified account is required.');
        }

        $result = $this->transactions->transactional(function () use (
            $identity,
            $invitationId,
            $expectedRevision,
            $user,
        ): array {
            $now = $this->clock->now();
            $result = $this->homes->acceptInvitationById(
                $invitationId,
                $identity->userId,
                (string) $user['normalized_email'],
                $expectedRevision,
                $now,
            );
            if (($result['outcome'] ?? null) === 'accepted' && ($result['changed'] ?? false) === true) {
                $this->identities->setActiveHome($identity->sessionId, (string) $result['homeId'], $now);
                $this->audit(
                    $identity,
                    'home.invitation.accepted',
                    'home_invitation',
                    (string) $result['invitationId'],
                    (string) $result['homeId'],
                    [],
                    $now,
                );
            }
            return $result;
        });
        match ($result['outcome'] ?? null) {
            'accepted' => null,
            'not-found' => throw new Problem(404, 'Not found', 'The invitation is unavailable.'),
            'expired' => throw new Problem(410, 'Invitation expired', 'Request a new home invitation.'),
            'revision-conflict' => throw new Problem(
                409,
                'Revision conflict',
                'The invitation changed since it was read.',
            ),
            default => throw new \LogicException('Unknown invitation acceptance result.'),
        };
        unset($result['outcome'], $result['changed']);

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function invitations(AuthenticatedIdentity $identity, string $homeId): array
    {
        $actor = $this->authorization->requirePermission(
            $identity,
            $homeId,
            HomePermission::MEMBERS_INVITE,
        );
        $invitations = $this->homes->invitations($homeId);
        if ((string) $actor['role'] === HomeAuthorization::OWNER) {
            return $invitations;
        }

        return array_values(array_filter(
            $invitations,
            static fn (array $invitation): bool =>
                (string) ($invitation['inviterUserId'] ?? '') === $identity->userId
                && (string) ($invitation['role'] ?? '') !== HomeAuthorization::MANAGER,
        ));
    }

    public function revokeInvitation(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $invitationId,
        int $expectedRevision,
    ): void {
        if ($expectedRevision < 1) {
            throw new Problem(422, 'Validation failed', 'A current invitation revision is required.');
        }
        $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $invitationId,
            $expectedRevision,
        ): void {
            $actor = $this->authorization->requirePermission(
                $identity,
                $homeId,
                HomePermission::MEMBERS_INVITE,
            );
            $invitation = $this->homes->invitation($homeId, $invitationId);
            if (
                $invitation === null
                || (
                    (string) $actor['role'] !== HomeAuthorization::OWNER
                    && (
                        (string) $invitation['inviterUserId'] !== $identity->userId
                        || (string) $invitation['role'] === HomeAuthorization::MANAGER
                    )
                )
            ) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
            $now = $this->clock->now();
            if (
                ! $this->homes->revokeInvitation(
                    $homeId,
                    $invitationId,
                    $expectedRevision,
                    $identity->userId,
                    $now,
                )
            ) {
                throw new Problem(409, 'Invitation conflict', 'The invitation is no longer pending at that revision.');
            }
            $this->audit(
                $identity,
                'home.invitation.revoked',
                'home_invitation',
                $invitationId,
                $homeId,
                [],
                $now,
            );
        });
    }

    /** @return array<string, mixed> */
    public function switch(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requireMember($identity, $homeId);
        $this->identities->setActiveHome($identity->sessionId, $homeId, $this->clock->now());

        return $this->get($identity, $homeId);
    }

    public function changeRole(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $userId,
        string $role,
        int $expectedRevision,
    ): void {
        $role = strtolower(trim($role));
        if (
            ! in_array(
                $role,
                [
                    HomeAuthorization::MANAGER,
                    HomeAuthorization::MEMBER,
                    HomeAuthorization::VIEWER,
                ],
                true,
            )
        ) {
            throw new Problem(422, 'Validation failed', 'Membership role is invalid.');
        }
        $this->transactions->transactional(function () use (
            $homeId,
            $userId,
            $role,
            $expectedRevision,
            $identity,
        ): void {
            $actor = $this->authorization->requirePermission(
                $identity,
                $homeId,
                HomePermission::MEMBERS_MANAGE,
            );
            if (
                (string) $actor['role'] !== HomeAuthorization::OWNER
                && $role === HomeAuthorization::MANAGER
            ) {
                throw new Problem(403, 'Forbidden', 'Only an owner can assign the manager role.');
            }
            $membership = $this->homes->membership($homeId, $userId);
            if ($membership === null) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
            if ((string) $membership['role'] === HomeAuthorization::OWNER) {
                throw new Problem(
                    409,
                    'Ownership safeguard',
                    'Use the explicit ownership-transfer command to change the owner.',
                );
            }
            $now = $this->clock->now();
            if (
                ! $this->homes->changeMembershipRole(
                    $homeId,
                    $userId,
                    $role,
                    $expectedRevision,
                    $now,
                )
            ) {
                throw new Problem(409, 'Revision conflict', 'The membership changed since it was read.');
            }
            $this->audit(
                $identity,
                'home.membership.role-changed',
                'home_membership',
                $userId,
                $homeId,
                ['role' => $role],
                $now,
            );
        });
    }

    public function leave(AuthenticatedIdentity $identity, string $homeId): void
    {
        $this->transactions->transactional(function () use ($homeId, $identity): void {
            $membership = $this->authorization->requireMember($identity, $homeId);
            if ((string) $membership['role'] === HomeAuthorization::OWNER) {
                throw new Problem(
                    409,
                    'Ownership safeguard',
                    'A sole owner must transfer ownership or delete the home.',
                );
            }
            $now = $this->clock->now();
            if (! $this->homes->removeMembership($homeId, $identity->userId, $now)) {
                throw new Problem(409, 'Membership conflict', 'The membership could not be removed.');
            }
            $this->identities->clearActiveHome($identity->userId, $homeId, $now);
            $this->audit(
                $identity,
                'home.membership.left',
                'home_membership',
                $identity->userId,
                $homeId,
                [],
                $now,
            );
        });
    }

    public function transferOwnership(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $targetUserId,
        int $expectedTargetRevision,
        string $stepUpToken = '',
    ): void {
        $this->proposeOwnershipTransfer(
            $identity,
            $homeId,
            $targetUserId,
            $expectedTargetRevision,
            $stepUpToken,
        );
    }

    /** @return array<string, mixed> */
    public function proposeOwnershipTransfer(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $targetUserId,
        int $expectedTargetRevision,
        string $stepUpToken,
    ): array {
        if ($targetUserId === $identity->userId) {
            throw new Problem(422, 'Validation failed', 'The target is already the owner.');
        }
        if ($expectedTargetRevision < 1 || $stepUpToken === '') {
            throw new Problem(422, 'Validation failed', 'A current target revision and step-up token are required.');
        }
        if ($this->authentication === null) {
            throw new \LogicException('Ownership transfer requires the authentication service.');
        }
        try {
            return $this->transactions->transactional(function () use (
                $identity,
                $homeId,
                $targetUserId,
                $expectedTargetRevision,
                $stepUpToken,
            ): array {
                $actor = $this->authorization->requirePermission(
                    $identity,
                    $homeId,
                    HomePermission::OWNERSHIP_TRANSFER,
                );
                if ((string) $actor['role'] !== HomeAuthorization::OWNER) {
                    throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
                }
                $target = $this->homes->membership($homeId, $targetUserId);
                if (
                    $target === null
                    || (string) $target['status'] !== 'active'
                    || (string) $target['role'] === HomeAuthorization::OWNER
                ) {
                    throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
                }
                if ((int) $target['revision'] !== $expectedTargetRevision) {
                    throw new Problem(409, 'Revision conflict', 'The target membership changed.');
                }
                $this->authentication->consumeStepUp($identity, $stepUpToken, 'ownership-transfer');
                $now = $this->clock->now();
                $id = $this->ids->generate();
                $expires = $now->add(new DateInterval('P1D'));
                $this->homes->createOwnershipTransfer(
                    $id,
                    $homeId,
                    $identity->userId,
                    $targetUserId,
                    $expectedTargetRevision,
                    $now,
                    $expires,
                    $now,
                );
                $this->audit(
                    $identity,
                    'home.ownership-transfer.proposed',
                    'home_ownership_transfer',
                    $id,
                    $homeId,
                    ['targetUserId' => $targetUserId],
                    $now,
                );

                return [
                    'id' => $id,
                    'homeId' => $homeId,
                    'proposedByUserId' => $identity->userId,
                    'targetUserId' => $targetUserId,
                    'status' => 'pending',
                    'expiresAt' => $expires->format(DATE_ATOM),
                    'revision' => 1,
                ];
            });
        } catch (\DomainException $error) {
            throw new Problem(409, 'Ownership transfer conflict', $error->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    public function ownershipTransfers(AuthenticatedIdentity $identity, string $homeId): array
    {
        $membership = $this->authorization->requireMember($identity, $homeId);

        return $this->homes->ownershipTransfers(
            $homeId,
            (string) $membership['role'] === HomeAuthorization::OWNER ? null : $identity->userId,
        );
    }

    public function acceptOwnershipTransfer(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $transferId,
        int $expectedRevision,
    ): void {
        $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $transferId,
            $expectedRevision,
        ): void {
            $this->authorization->requireMember($identity, $homeId);
            $transfer = $this->requiredOwnershipTransfer($homeId, $transferId, $expectedRevision);
            if ((string) $transfer['targetUserId'] !== $identity->userId) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
            $target = $this->homes->membership($homeId, $identity->userId);
            $owner = $this->homes->membership($homeId, (string) $transfer['proposedByUserId']);
            if (
                $target === null
                || (string) $target['status'] !== 'active'
                || (int) $target['revision'] !== (int) $transfer['expectedTargetRevision']
                || $owner === null
                || (string) $owner['status'] !== 'active'
                || (string) $owner['role'] !== HomeAuthorization::OWNER
            ) {
                throw new Problem(409, 'Ownership transfer conflict', 'Ownership or membership changed.');
            }
            $now = $this->clock->now();
            if (
                ! $this->homes->transitionOwnershipTransfer(
                    $homeId,
                    $transferId,
                    $expectedRevision,
                    'accepted',
                    $now,
                )
            ) {
                throw new Problem(409, 'Ownership transfer conflict', 'The proposal changed or expired.');
            }
            if (
                ! $this->homes->transferOwnership(
                    $homeId,
                    (string) $transfer['proposedByUserId'],
                    $identity->userId,
                    (int) $transfer['expectedTargetRevision'],
                    $now,
                )
            ) {
                throw new Problem(409, 'Ownership transfer conflict', 'Ownership or membership changed.');
            }
            $this->audit(
                $identity,
                'home.ownership-transfer.accepted',
                'home_ownership_transfer',
                $transferId,
                $homeId,
                ['previousOwnerUserId' => (string) $transfer['proposedByUserId']],
                $now,
            );
        });
    }

    public function rejectOwnershipTransfer(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $transferId,
        int $expectedRevision,
    ): void {
        $this->transitionOwnershipTransferForActor(
            $identity,
            $homeId,
            $transferId,
            $expectedRevision,
            'rejected',
            'targetUserId',
        );
    }

    public function revokeOwnershipTransfer(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $transferId,
        int $expectedRevision,
    ): void {
        $this->transitionOwnershipTransferForActor(
            $identity,
            $homeId,
            $transferId,
            $expectedRevision,
            'revoked',
            'proposedByUserId',
        );
    }

    /** @return array<string, mixed> */
    private function requiredOwnershipTransfer(
        string $homeId,
        string $transferId,
        int $expectedRevision,
    ): array {
        $transfer = $this->homes->ownershipTransfer($homeId, $transferId);
        if ($transfer === null) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
        if ((string) $transfer['status'] !== 'pending' || (int) $transfer['revision'] !== $expectedRevision) {
            throw new Problem(409, 'Ownership transfer conflict', 'The proposal changed.');
        }

        return $transfer;
    }

    private function transitionOwnershipTransferForActor(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $transferId,
        int $expectedRevision,
        string $status,
        string $actorField,
    ): void {
        $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $transferId,
            $expectedRevision,
            $status,
            $actorField,
        ): void {
            $this->authorization->requireMember($identity, $homeId);
            $transfer = $this->requiredOwnershipTransfer($homeId, $transferId, $expectedRevision);
            if ((string) $transfer[$actorField] !== $identity->userId) {
                throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
            }
            $now = $this->clock->now();
            if (
                ! $this->homes->transitionOwnershipTransfer(
                    $homeId,
                    $transferId,
                    $expectedRevision,
                    $status,
                    $now,
                )
            ) {
                throw new Problem(409, 'Ownership transfer conflict', 'The proposal changed or expired.');
            }
            $this->audit(
                $identity,
                'home.ownership-transfer.' . $status,
                'home_ownership_transfer',
                $transferId,
                $homeId,
                [],
                $now,
            );
        });
    }

    /** @param array<string, mixed> $details */
    private function audit(
        AuthenticatedIdentity $identity,
        string $action,
        string $targetType,
        string $targetId,
        string $homeId,
        array $details,
        \DateTimeImmutable $at,
    ): void {
        $this->homes->recordAudit(
            $this->ids->generate(),
            $identity->userId,
            $action,
            $targetType,
            $targetId,
            $homeId,
            json_encode($details, JSON_THROW_ON_ERROR),
            $at,
        );
    }
}
