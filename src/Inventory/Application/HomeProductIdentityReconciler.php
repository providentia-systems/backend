<?php

declare(strict_types=1);

namespace Providentia\Inventory\Application;

use Providentia\Home\Application\HomeStore;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;

/** Explicit, home-scoped maintenance. Dry-run is the default, not a migration side effect. */
final readonly class HomeProductIdentityReconciler
{
    public function __construct(
        private HomeProductIdentityRepairStore $store,
        private HomeStore $homes,
        private ChangeFeedWriter $changes,
        private TransactionManager $transactions,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(string $homeId, ?string $afterId, int $limit, ?string $actorUserId = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000.');
        }
        if ($actorUserId !== null) {
            $this->requireOwner($homeId, $actorUserId);
        }
        $rows = $this->store->scan($homeId, $afterId, $limit + 1);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $report = [];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if ($actorUserId !== null && $status === 'repairable') {
                $status = $this->transactions->transactional(function () use ($homeId, $row, $actorUserId): string {
                    $this->requireOwner($homeId, $actorUserId);
                    $at = $this->clock->now();
                    $result = $this->store->repair($homeId, (string) $row['id'], (int) $row['revision'], $at);
                    if ($result['status'] === 'updated') {
                        /** @var array<string, mixed> $representation */
                        $representation = $result['representation'];
                        $this->changes->put(
                            $homeId,
                            $actorUserId,
                            'inventory-home-product',
                            (string) $row['id'],
                            (int) $result['revision'],
                            $representation,
                            $at,
                        );
                    }

                    return (string) $result['status'];
                });
            }
            // Do not print product names, source wording, household quantities or histories.
            $report[] = ['id' => $row['id'], 'identity' => $row['identity'], 'status' => $status];
        }

        return [
            'homeId' => $homeId,
            'dryRun' => $actorUserId === null,
            'scanned' => count($rows),
            'records' => $report,
            'hasMore' => $hasMore,
            'nextAfterId' => $hasMore && $rows !== [] ? $rows[count($rows) - 1]['id'] : null,
        ];
    }

    private function requireOwner(string $homeId, string $actorUserId): void
    {
        $membership = $this->homes->membership($homeId, $actorUserId);
        if (($membership['status'] ?? null) !== 'active' || ($membership['role'] ?? null) !== 'owner') {
            throw new \DomainException('Maintenance attribution requires an active owner of this home.');
        }
    }
}
