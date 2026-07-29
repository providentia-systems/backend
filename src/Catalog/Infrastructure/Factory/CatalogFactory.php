<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Catalog\Application\CatalogQueryService;
use Providentia\Catalog\Application\CatalogSeedService;
use Providentia\Catalog\Application\CatalogStore;
use Providentia\Catalog\Http\CatalogSearchHandler;
use Providentia\Catalog\Infrastructure\Cli\CatalogSeedCommand;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class CatalogFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        return match ($requestedName) {
            DbalCatalogStore::class => new DbalCatalogStore(
                $container->get(Connection::class),
                $container->get(UuidGenerator::class),
            ),
            CatalogQueryService::class => new CatalogQueryService($container->get(CatalogStore::class)),
            CatalogSeedService::class => new CatalogSeedService(
                $container->get(CatalogStore::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            CatalogSearchHandler::class => new CatalogSearchHandler(
                $container->get(CatalogQueryService::class),
            ),
            CatalogSeedCommand::class => new CatalogSeedCommand(
                $container->get(CatalogSeedService::class),
            ),
            default => throw new \LogicException('Unsupported catalog service: ' . $requestedName),
        };
    }
}
