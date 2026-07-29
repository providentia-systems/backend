<?php

declare(strict_types=1);

namespace Providentia\Home\Application;

use DateInterval;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\Identity\Application\IdentityStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Http\HttpProblem;

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
            throw new HttpProblem(422, 'Validation failed', 'Home name must contain 1 to 120 characters.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new HttpProblem(422, 'Validation failed', 'Currency must be a three-letter ISO code.');
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

        return (array) $this->homes->findHome($id);
    }

    /** @return list<array<string, mixed>> */
    public function list(AuthenticatedIdentity $identity): array
    {
        return $this->homes->listForUser($identity->userId);
    }

    /** @return array<string, mixed> */
    public function get(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requireMember($identity, $homeId);
        $home = $this->homes->findHome($homeId);
        if ($home === null) {
            throw new HttpProblem(404, 'Not found', 'The requested resource is unavailable.');
        }

        return $home;
    }

    /** @return list<array<string, mixed>> */
    public function memberships(AuthenticatedIdentity $identity, string $homeId): array
    {
        $this->authorization->requireRole(
            $identity,
            $homeId,
            [HomeAuthorization::OWNER, HomeAuthorization::MANAGER],
        );

        return $this->homes->memberships($homeId);
    }

    /** @return array{invitationId: string, invitationToken: string, expiresAt: string} */
    public function invite(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $email,
        string $role,
    ): array {
        $role = strtolower(trim($role));
        $allowed = [HomeAuthorization::MANAGER, HomeAuthorization::MEMBER, HomeAuthorization::VIEWER];
        if (! in_array($role, $allowed, true)) {
            throw new HttpProblem(422, 'Validation failed', 'Invitation role is invalid.');
        }
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpProblem(422, 'Validation failed', 'A valid invitation email is required.');
        }

        $id = $this->ids->generate();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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
            $actor = $this->authorization->requireRole(
                $identity,
                $homeId,
                [HomeAuthorization::OWNER, HomeAuthorization::MANAGER],
            );
            if (
                (string) $actor['role'] === HomeAuthorization::MANAGER
                && $role === HomeAuthorization::MANAGER
            ) {
                throw new HttpProblem(403, 'Forbidden', 'Managers cannot create peer manager memberships.');
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
        });
        $home = $this->homes->findHome($homeId);
        $this->notifications->sendHomeInvitation(
            $email,
            (string) ($home['name'] ?? 'Providentia home'),
            $role,
            $token,
        );

        return ['invitationId' => $id, 'invitationToken' => $token, 'expiresAt' => $expires->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    public function acceptInvitation(AuthenticatedIdentity $identity, string $token): array
    {
        $user = $this->identities->findUserById($identity->userId);
        if ($user === null) {
            throw new HttpProblem(401, 'Authentication required', 'The account is unavailable.');
        }
        $result = $this->transactions->transactional(function () use ($token, $identity, $user): array {
            $result = $this->homes->acceptInvitation(
                $this->hasher->hashToken($token),
                $identity->userId,
                (string) $user['normalized_email'],
                $this->clock->now(),
            );
            if ($result === null) {
                throw new HttpProblem(
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
            throw new HttpProblem(422, 'Validation failed', 'Membership role is invalid.');
        }
        $this->transactions->transactional(function () use (
            $homeId,
            $userId,
            $role,
            $expectedRevision,
            $identity,
        ): void {
            $this->authorization->requireRole($identity, $homeId, [HomeAuthorization::OWNER]);
            $membership = $this->homes->membership($homeId, $userId);
            if ($membership === null) {
                throw new HttpProblem(404, 'Not found', 'The requested resource is unavailable.');
            }
            if ((string) $membership['role'] === HomeAuthorization::OWNER) {
                throw new HttpProblem(
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
                throw new HttpProblem(409, 'Revision conflict', 'The membership changed since it was read.');
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
                throw new HttpProblem(
                    409,
                    'Ownership safeguard',
                    'A sole owner must transfer ownership or delete the home.',
                );
            }
            $now = $this->clock->now();
            if (! $this->homes->removeMembership($homeId, $identity->userId, $now)) {
                throw new HttpProblem(409, 'Membership conflict', 'The membership could not be removed.');
            }
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
    ): void {
        if ($targetUserId === $identity->userId) {
            throw new HttpProblem(422, 'Validation failed', 'The target is already the owner.');
        }
        $this->transactions->transactional(function () use (
            $identity,
            $homeId,
            $targetUserId,
            $expectedTargetRevision,
        ): void {
            $this->authorization->requireRole($identity, $homeId, [HomeAuthorization::OWNER]);
            $now = $this->clock->now();
            if (
                ! $this->homes->transferOwnership(
                    $homeId,
                    $identity->userId,
                    $targetUserId,
                    $expectedTargetRevision,
                    $now,
                )
            ) {
                throw new HttpProblem(
                    409,
                    'Ownership transfer conflict',
                    'The target membership or ownership changed. Reload and retry explicitly.',
                );
            }
            $this->audit(
                $identity,
                'home.ownership.transferred',
                'home_membership',
                $targetUserId,
                $homeId,
                ['previousOwnerUserId' => $identity->userId],
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
