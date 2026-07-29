<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Providentia\SharedKernel\Application\Health\DatabaseReadinessProbe;
use Throwable;

final class DoctrineDatabaseReadinessProbe implements DatabaseReadinessProbe
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function check(): array
    {
        try {
            $this->connection->fetchOne('SELECT 1');

            return ['status' => 'up'];
        } catch (Throwable) {
            return ['status' => 'down'];
        }
    }
}
