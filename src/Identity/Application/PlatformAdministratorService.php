<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class PlatformAdministratorService
{
    private const ROLE = 'platform_administrator';

    public function __construct(
        private readonly IdentityStore $identities,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly AccountNotificationSender $notifications,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(AuthenticatedIdentity $identity): array
    {
        $this->requireAdministrator($identity);

        return $this->identities->listPlatformAdministrators();
    }

    /** @return array<string, mixed> */
    public function grant(AuthenticatedIdentity $identity, string $email): array
    {
        $this->requireAdministrator($identity);
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new Problem(422, 'Validation failed', 'A valid administrator email is required.');
        }

        try {
            $result = $this->transactions->transactional(function () use ($identity, $email): array {
                $result = $this->identities->grantPlatformAdministrator(
                    $this->ids->generate(),
                    $this->ids->generate(),
                    $identity->userId,
                    $email,
                    $this->clock->now(),
                );
                if (($result['changed'] ?? false) === true && ($result['status'] ?? null) === 'pending') {
                    $this->notifications->sendPlatformAdministratorInvitation($email);
                }

                return $result;
            });
        } catch (ConcurrentPlatformRoleChange) {
            throw new Problem(409, 'Revision conflict', 'The account role changed concurrently.');
        }
        unset($result['changed']);

        return $result;
    }

    public function revoke(
        AuthenticatedIdentity $identity,
        string $administratorId,
        int $expectedRevision,
    ): void {
        $this->requireAdministrator($identity);
        if ($expectedRevision < 1) {
            throw new Problem(422, 'Validation failed', 'A positive expected revision is required.');
        }
        try {
            $result = $this->transactions->transactional(fn (): string =>
                $this->identities->revokePlatformAdministrator(
                    $this->ids->generate(),
                    $identity->userId,
                    $administratorId,
                    $expectedRevision,
                    $this->clock->now(),
                ));
        } catch (ConcurrentPlatformRoleChange) {
            throw new Problem(409, 'Revision conflict', 'The account role changed concurrently.');
        }
        match ($result) {
            'revoked' => null,
            'not-found' => throw new Problem(404, 'Not found', 'The administrator is unavailable.'),
            'revision-conflict' => throw new Problem(
                409,
                'Revision conflict',
                'The administrator grant changed since it was read.',
            ),
            'last-administrator' => throw new Problem(
                409,
                'Last administrator safeguard',
                'Grant another active administrator before revoking this one.',
            ),
            default => throw new \LogicException('Unknown administrator revocation result.'),
        };
    }

    private function requireAdministrator(AuthenticatedIdentity $identity): void
    {
        if (! in_array(self::ROLE, $identity->platformRoles, true)) {
            throw new Problem(403, 'Forbidden', 'Platform-administrator authority is required.');
        }
    }
}
