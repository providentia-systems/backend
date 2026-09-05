<?php

declare(strict_types=1);

namespace Providentia\Administration\Application;

use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AccountProfileStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;

final class OperatorWorkspaceService
{
    public function __construct(
        private readonly OperatorWorkspaceStore $store,
        private readonly AccessService $access,
        private readonly AccessStore $groups,
        private readonly AccountProfileStore $profiles,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>> */
    public function homes(
        AuthenticatedIdentity $admin,
        string $search,
        int $offset,
    ): array {
        $this->access->requireAdmin($admin, 'homes.read');
        return $this->store->homes($search, $offset);
    }

    /**
     * @return array<string, mixed> */
    public function home(
        AuthenticatedIdentity $admin,
        string $id,
    ): array {
        $this->access->requireAdmin($admin, 'homes.read');
        $home = $this->store->home($id) ?? throw new Problem(
            404,
            'Home unavailable',
            'The home does not exist.',
        );
        $home['access'] = $this->access->effective(FeatureCatalog::HOME, $id);
        $this->groups->audit(
            $admin->userId,
            'operator.home.viewed',
            FeatureCatalog::HOME,
            $id,
            [],
        );
        return $home;
    }

    /**
     * @return list<array<string, mixed>> */
    public function records(
        AuthenticatedIdentity $admin,
        string $homeId,
        string $collection,
        int $offset,
    ): array {
        $this->access->requireAdmin($admin, 'homes.read');
        if (
            in_array(
                $collection,
                ['memberships', 'invitations'],
                true,
            )
        ) {
            $this->access->requireAdmin($admin, 'people.read');
        }
        $records = $this->store->records($homeId, $collection, $offset);
        $this->groups->audit(
            $admin->userId,
            'operator.home.records.viewed',
            FeatureCatalog::HOME,
            $homeId,
            ['collection' => $collection, 'offset' => $offset],
        );
        return $records;
    }

    /**
     * @return list<array<string, mixed>> */
    public function administrators(AuthenticatedIdentity $admin): array
    {
        $this->access->requireAdmin($admin, 'administrators.read');
        return $this->store->administrators();
    }

    /**
     * @param array<string, mixed> $input */
    public function reviewAdministrator(
        AuthenticatedIdentity $admin,
        string $userId,
        array $input,
    ): void {
        $this->access->requireAdmin($admin, 'administrators.approve');
        if ($admin->userId === $userId || $this->profiles->isSystemOwner($userId)) {
            throw new Problem(
                403,
                'Protected administrator',
                'You cannot review your own access or change the system owner.',
            );
        }
        $status = (string) ($input['status'] ?? '');
        if (
            !in_array(
                $status,
                ['approved', 'rejected', 'suspended'],
                true,
            )
        ) {
            throw new Problem(
                422,
                'Invalid decision',
                'Choose approved, rejected or suspended.',
            );
        }
        $groupId = (string) ($input['groupId'] ?? '');
        if ($status === 'approved') {
            $group = $this->groups->group($groupId);
            if ($group === null || $group['scope'] !== FeatureCatalog::ADMIN || $group['protected']) {
                throw new Problem(
                    422,
                    'Invalid group',
                    'Choose an editable administrator group.',
                );
            }
            foreach ($group['features'] as $permission => $enabled) {
                if ($enabled === true) {
                    $this->access->requireAdmin($admin, (string) $permission);
                }
            }
        }
        $this->transactions->transactional(
            function () use ($admin, $userId, $input, $status, $groupId): void {
                $this->groups->lockSubject(FeatureCatalog::ADMIN, $userId);
                if (
                    !$this->store->reviewAdministrator(
                        $userId,
                        $admin->userId,
                        $status,
                        (int) ($input['expectedRevision'] ?? 0),
                        $this->clock->now()
                        ->format('Y-m-d H:i:s'),
                    )
                ) {
                    throw new Problem(
                        409,
                        'Revision conflict',
                        'Reload the administrator request.',
                    );
                }
                if (
                    $status === 'approved' && !$this->groups->assign(
                        FeatureCatalog::ADMIN,
                        $userId,
                        $groupId,
                        (int) ($input['assignmentRevision'] ?? 0),
                    )
                ) {
                    throw new Problem(
                        409,
                        'Assignment changed',
                        'Reload the administrator group assignment.',
                    );
                }
                $this->groups->audit(
                    $admin->userId,
                    'administrator.reviewed',
                    FeatureCatalog::ADMIN,
                    $userId,
                    ['status' => $status, 'groupId' => $groupId],
                );
            },
        );
    }

    /**
     * @return list<array<string, mixed>> */
    public function audit(
        AuthenticatedIdentity $admin,
        int $offset,
    ): array {
        $this->access->requireAdmin($admin, 'audit.read');
        return $this->groups->auditEvents(100, $offset);
    }
}
