<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Psr\Container\ContainerInterface;

final class ConnectionFactory
{
    public function __invoke(ContainerInterface $container): Connection
    {
        /** @var array{database: array{url: string}} $config */
        $config = $container->get('config');

        $parser = new DsnParser([
            'mariadb' => 'pdo_mysql',
            'mysql' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
        ]);

        return DriverManager::getConnection($parser->parse($config['database']['url']));
    }
}
