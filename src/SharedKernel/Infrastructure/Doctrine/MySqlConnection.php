<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\TransactionIsolationLevel;
use Throwable;

/** Keeps post-lock projection reads current on MySQL and MariaDB, including reconnects. */
final class MySqlConnection extends Connection
{
    protected function connect(): DriverConnection
    {
        $wasConnected = $this->isConnected();
        $connection = parent::connect();
        if (! $wasConnected) {
            try {
                // A default REPEATABLE READ snapshot established before waiting
                // for a product/receipt lock cannot see the winning transaction.
                // Configure only upon connection so factory construction stays lazy.
                parent::setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
            } catch (Throwable $error) {
                $this->close();
                throw $error;
            }
        }

        return $connection;
    }
}
