<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Providentia\Synchronization\Application\SyncDeploymentInformation;

/** Authenticated support evidence containing no credentials or household content. */
final class DbalSyncDeploymentInformation implements SyncDeploymentInformation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectRoot,
        private readonly string $applicationVersion,
        private readonly string $buildRevision,
    ) {
    }

    public function information(): array
    {
        $contract = $this->projectRoot . '/contracts/openapi/providentia-v1.json';
        $contractHash = is_file($contract) ? hash_file('sha256', $contract) : null;
        $lockPath = $this->projectRoot . '/contracts/openapi/contract.lock.json';
        $lock = is_file($lockPath) ? json_decode((string) file_get_contents($lockPath), true) : null;
        $lockedHash = is_array($lock) ? ($lock['artifacts']['providentia-v1.json']['sha256'] ?? null) : null;
        $available = array_map(
            static fn (string $file): string => 'Providentia\\Migrations\\' . basename($file, '.php'),
            glob($this->projectRoot . '/migrations/Version*.php') ?: [],
        );
        sort($available, SORT_STRING);
        $initialized = $this->connection->createSchemaManager()->tablesExist(['doctrine_migration_versions']);
        $applied = $initialized
            ? array_map(
                'strval',
                $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions'),
            )
            : [];
        sort($applied, SORT_STRING);
        $pending = array_values(array_diff($available, $applied));
        $unknown = array_values(array_diff($applied, $available));
        $migrationStatus = match (true) {
            ! $initialized => 'uninitialized',
            $unknown !== [] => 'divergent',
            $pending !== [] => 'pending',
            default => 'current',
        };

        return [
            'applicationVersion' => $this->applicationVersion,
            'buildRevision' => preg_match('/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/D', $this->buildRevision) === 1
                ? $this->buildRevision : null,
            'contractHash' => is_string($contractHash) ? $contractHash : null,
            'contractMatchesLock' => is_string($contractHash) && is_string($lockedHash)
                && hash_equals($lockedHash, $contractHash),
            'migrationStatus' => $migrationStatus,
            'availableMigrations' => $available,
            'appliedMigrations' => $applied,
            'pendingMigrations' => $pending,
            'unknownMigrations' => $unknown,
        ];
    }
}
