<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure;

use Doctrine\DBAL\Connection;
use Providentia\SharedKernel\Application\SystemInformationProvider;

final class RuntimeSystemInformationProvider implements SystemInformationProvider
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $environment,
        private readonly string $version,
        private readonly string $queueBroker,
    ) {
    }

    public function information(): array
    {
        return [
            'product' => 'Providentia',
            'apiVersion' => 'v1',
            'applicationVersion' => $this->version,
            'environment' => $this->environment,
            'runtime' => 'PHP ' . PHP_VERSION,
            'databaseDriver' => $this->connection->getDriver()::class,
            'queueAdapter' => 'enqueue-redis',
            'queueBroker' => $this->queueBroker,
        ];
    }
}
