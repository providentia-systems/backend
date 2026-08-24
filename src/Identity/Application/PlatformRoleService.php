<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class PlatformRoleService
{
    public const ADMINISTRATOR = 'platform_administrator';
    public const CATALOG_CURATOR = 'catalog_curator';
    public const CATALOG_REVIEWER = 'catalog_reviewer';
    public const BILLING_OPERATOR = 'billing_operator';

    private const ROLES = [
        self::ADMINISTRATOR,
        self::CATALOG_CURATOR,
        self::CATALOG_REVIEWER,
        self::BILLING_OPERATOR,
    ];

    public function __construct(
        private readonly PlatformRoleStore $roles,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function grant(
        AuthenticatedIdentity $identity,
        string $userId,
        string $role,
        int $expectedRevision,
    ): void {
        $this->requireAdministrator($identity);

        $this->applyChange($identity->userId, $userId, $role, true, $expectedRevision);
    }

    public function revoke(
        AuthenticatedIdentity $identity,
        string $userId,
        string $role,
        int $expectedRevision,
    ): void {
        $this->requireAdministrator($identity);

        $this->applyChange($identity->userId, $userId, $role, false, $expectedRevision);
    }

    /**
     * CLI-only owner path. The HTTP control plane never resolves roles by email.
     *
     * @return array<string, mixed>
     */
    public function changeVerifiedEmail(string $email, string $role, bool $grant): array
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new Problem(422, 'Validation failed', 'A valid verified account email is required.');
        }
        $account = $this->roles->verifiedAccountByEmail($email);
        if ($account === null) {
            throw new Problem(404, 'Not found', 'No active verified account matched.');
        }

        $changed = $this->applyChange(
            null,
            (string) $account['userId'],
            $role,
            $grant,
            (int) $account['revision'],
        );

        return [
            'userId' => (string) $account['userId'],
            'revision' => (int) $account['revision'] + ($changed ? 1 : 0),
        ];
    }

    private function applyChange(
        ?string $actorUserId,
        string $userId,
        string $role,
        bool $grant,
        int $expectedRevision,
    ): bool {
        if (! in_array($role, self::ROLES, true)) {
            throw new Problem(422, 'Validation failed', 'The platform role is not supported.');
        }
        if ($expectedRevision < 1) {
            throw new Problem(422, 'Validation failed', 'A positive expected revision is required.');
        }
        $result = $this->transactions->transactional(fn (): string =>
            $this->roles->changePlatformRole(
                $this->ids->generate(),
                $actorUserId,
                $userId,
                $role,
                $grant,
                $expectedRevision,
                $this->clock->now(),
            ));
        return match ($result) {
            'updated' => true,
            'unchanged' => false,
            'not-found' => throw new Problem(404, 'Not found', 'The account is unavailable.'),
            'revision-conflict' => throw new Problem(
                409,
                'Revision conflict',
                'The account changed since it was read.',
            ),
            'closed-account' => throw new Problem(
                409,
                'Closed account',
                'Roles cannot be changed on a closed account.',
            ),
            'last-administrator' => throw new Problem(
                409,
                'Last administrator safeguard',
                'Grant another active administrator before revoking this role.',
            ),
            default => throw new \LogicException('Unknown platform-role change result.'),
        };
    }

    private function requireAdministrator(AuthenticatedIdentity $identity): void
    {
        if (! in_array(self::ADMINISTRATOR, $identity->platformRoles, true)) {
            throw new Problem(403, 'Forbidden', 'Platform-administrator authority is required.');
        }
    }
}
