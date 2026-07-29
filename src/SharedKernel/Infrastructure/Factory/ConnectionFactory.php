<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Container\ContainerInterface;

final class ConnectionFactory
{
    public function __invoke(ContainerInterface $container): Connection
    {
        /** @var array{database: array{url: string}} $config */
        $config = $container->get('config');

        return DriverManager::getConnection(['url' => $config['database']['url']]);
    }
}

