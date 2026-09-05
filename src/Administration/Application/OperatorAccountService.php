<?php

declare(strict_types=1);

namespace Providentia\Administration\Application;

use Providentia\Billing\Application\OperatorSubscriptionReader;
use Providentia\Home\Application\OperatorHomeAccessReader;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\OperatorAccountControl;
use Providentia\Identity\Application\OperatorIdentityDirectory;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

/**
 * Composes privacy-safe operator views through module application ports.
 * Identity, Home, and Billing infrastructure remain responsible for their own
 * tables; this administration use case never reaches across module storage.
 */
final class OperatorAccountService
{
    public function __construct(
        private readonly OperatorIdentityDirectory $identities,
        private readonly OperatorAccountControl $accountControl,
        private readonly OperatorHomeAccessReader $homes,
        private readonly OperatorSubscriptionReader $subscriptions,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly \Providentia\Identity\Application\AccountProfileStore $profiles,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, pagination: array<string, int|bool|null>} */
    public function list(
        AuthenticatedIdentity $identity,
        string $search,
        ?string $status,
        int $limit,
        int $offset,
    ): array {
        $this->requireAdministrator($identity, 'accounts.read');
        $search = trim($search);
        if (mb_strlen($search) > 191) {
            throw new Problem(422, 'Validation failed', 'Account search must not exceed 191 characters.');
        }
        $status = $status === null || trim($status) === '' ? null : trim($status);
        if ($status !== null && ! in_array($status, ['active', 'suspended', 'closed'], true)) {
            throw new Problem(422, 'Validation failed', 'Account status must be active, suspended, or closed.');
        }
        $limit = min(100, max(1, $limit));
        $offset = max(0, $offset);
        $page = $this->identities->operatorAccounts($search, $status, $limit, $offset, $this->clock->now());
        $homeAccess = $this->homes->operatorHomeAccess(array_values(array_map(
            static fn (array $account): string => (string) $account['userId'],
            $page['items'],
        )));
        foreach ($page['items'] as &$account) {
            $memberships = $homeAccess[(string) $account['userId']] ?? [];
            $account['homeCount'] = count(array_filter(
                $memberships,
                static fn (array $membership): bool => $membership['membershipStatus'] === 'active',
            ));
        }
        unset($account);
        $returned = count($page['items']);
        $nextOffset = $offset + $returned;
        $hasMore = $nextOffset < $page['total'];

        return [
            'data' => $page['items'],
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'returned' => $returned,
                'total' => $page['total'],
                'hasMore' => $hasMore,
                'nextOffset' => $hasMore ? $nextOffset : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function get(AuthenticatedIdentity $identity, string $userId): array
    {
        $this->requireAdministrator($identity, 'accounts.read');
        $account = $this->identities->operatorAccount($userId, $this->clock->now());
        if ($account === null) {
            throw new Problem(404, 'Not found', 'The account is unavailable.');
        }
        $memberships = $this->homes->operatorHomeAccess([$userId])[$userId] ?? [];
        $account['homeCount'] = count(array_filter(
            $memberships,
            static fn (array $membership): bool => $membership['membershipStatus'] === 'active',
        ));
        $subscriptions = $memberships === []
            ? []
            : $this->subscriptions->operatorSubscriptions(array_values(array_map(
                static fn (array $membership): string => (string) $membership['homeId'],
                $memberships,
            )));
        $account['homes'] = array_map(static function (array $membership) use ($subscriptions): array {
            $membership['subscription'] = $subscriptions[(string) $membership['homeId']] ?? null;

            return $membership;
        }, $memberships);

        return $account;
    }

    /** @return array<string, mixed> */
    public function updateStatus(
        AuthenticatedIdentity $identity,
        string $userId,
        string $status,
        string $reason,
        int $expectedRevision,
    ): array {
        $this->requireAdministrator($identity, 'accounts.manage');
        if ($this->profiles->isSystemOwner($userId) && $status !== 'active') {
            throw new Problem(409, 'System owner protected', 'The system owner account cannot be suspended or closed.');
        }
        if (! in_array($status, ['active', 'suspended', 'closed'], true)) {
            throw new Problem(422, 'Validation failed', 'Account status must be active, suspended, or closed.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new Problem(422, 'Validation failed', 'A reason containing 5 to 500 characters is required.');
        }
        if ($expectedRevision < 1) {
            throw new Problem(422, 'Validation failed', 'A positive expected revision is required.');
        }
        $result = $this->transactions->transactional(fn (): string =>
            $this->accountControl->updateOperatorAccountStatus(
                $this->ids->generate(),
                $identity->userId,
                $userId,
                $status,
                $reason,
                $expectedRevision,
                $this->clock->now(),
            ));
        match ($result) {
            'updated', 'unchanged' => null,
            'not-found' => throw new Problem(404, 'Not found', 'The account is unavailable.'),
            'revision-conflict' => throw new Problem(
                409,
                'Revision conflict',
                'The account changed since it was read.',
            ),
            'closed-terminal' => throw new Problem(409, 'Closed account', 'A closed account cannot be reactivated.'),
            'last-administrator' => throw new Problem(
                409,
                'Last administrator safeguard',
                'Grant another active administrator before disabling this account.',
            ),
            default => throw new \LogicException('Unknown account-status change result.'),
        };

        return $this->get($identity, $userId);
    }





    private function requireAdministrator(AuthenticatedIdentity $identity, string $permission): void
    {
        if (! in_array($permission, $identity->administratorPermissions, true)) {
            throw new Problem(403, 'Forbidden', 'Platform-administrator authority is required.');
        }
    }
}
