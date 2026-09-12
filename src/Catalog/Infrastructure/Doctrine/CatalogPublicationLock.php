<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/** One shared serialization point for global alias claims; it stores no identity or private data. */
final class CatalogPublicationLock
{
    public static function aliases(Connection $connection): void
    {
        if (! $connection->isTransactionActive()) {
            throw new \LogicException('Catalog alias publication requires a transaction.');
        }
        // The harmless write also serializes SQLite. The following locking read
        // checks the seeded row without relying on affected-row counting rules.
        $connection->executeStatement(
            'UPDATE catalog_governance_locks SET resource = resource WHERE resource = ?',
            ['global-alias-publication'],
        );
        $sql = 'SELECT resource FROM catalog_governance_locks WHERE resource = ?';
        if (! $connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $sql .= ' FOR UPDATE';
        }
        if ($connection->fetchOne($sql, ['global-alias-publication']) === false) {
            throw new \LogicException('The catalog publication lock is unavailable. Run the current migrations.');
        }
    }
}
