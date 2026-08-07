<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Factory;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PDO;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class ConnectionFactory
{
    /** @var Closure(): list<string> */
    private readonly Closure $availablePdoDrivers;

    /** @param null|Closure(): list<string> $availablePdoDrivers */
    public function __construct(?Closure $availablePdoDrivers = null)
    {
        $this->availablePdoDrivers = $availablePdoDrivers
            ?? static fn (): array => array_values(PDO::getAvailableDrivers());
    }

    public function __invoke(ContainerInterface $container): Connection
    {
        /** @var array{database: array{url: string}} $config */
        $config = $container->get('config');

        $parser = new DsnParser([
            'mariadb' => 'pdo_mysql',
            'mysql' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
        ]);

        $parameters = $parser->parse($config['database']['url']);
        $this->assertPdoDriverIsAvailable((string) ($parameters['driver'] ?? ''));

        return DriverManager::getConnection($parameters);
    }

    private function assertPdoDriverIsAvailable(string $driver): void
    {
        $pdoDriver = match ($driver) {
            'pdo_mysql' => 'mysql',
            'pdo_sqlite' => 'sqlite',
            default => null,
        };
        if ($pdoDriver === null || in_array($pdoDriver, ($this->availablePdoDrivers)(), true)) {
            return;
        }

        $extension = 'pdo_' . $pdoDriver;
        $package = $pdoDriver === 'sqlite' ? 'php8.5-sqlite3' : 'php8.5-mysql';

        throw new RuntimeException(sprintf(
            "DATABASE_URL requires the PHP %s extension, but its PDO driver is unavailable. "
            . "Install it (Ubuntu 26.04: sudo apt install %s), restart PHP, and rerun the command; "
            . 'alternatively use the supported Docker Compose runtime.',
            $extension,
            $package,
        ));
    }
}
