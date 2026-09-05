<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogGovernanceService;
use Providentia\Catalog\Application\CatalogGovernanceStore;
use Providentia\Catalog\Application\CatalogMergeHomeProductGateway;
use Providentia\Catalog\Application\CatalogQueryService;
use Providentia\Catalog\Application\CatalogSeedService;
use Providentia\Catalog\Application\CatalogStore;
use Providentia\Catalog\Http\CatalogCategoryHandler;
use Providentia\Catalog\Http\CatalogSearchHandler;
use Providentia\Catalog\Http\CatalogGovernanceHandler;
use Providentia\Catalog\Http\CatalogProductHandler;
use Providentia\Catalog\Infrastructure\Cli\CatalogSeedCommand;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogGovernanceStore;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class CatalogFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        return match (true) {
            $requestedName === DbalCatalogStore::class => new DbalCatalogStore(
                $container->get(Connection::class),
                $container->get(UuidGenerator::class),
            ),
            $requestedName === DbalCatalogGovernanceStore::class => new DbalCatalogGovernanceStore(
                $container->get(Connection::class),
                $container->get(UuidGenerator::class),
                $container->get(CatalogMergeHomeProductGateway::class),
            ),
            $requestedName === CatalogQueryService::class => new CatalogQueryService(
                $container->get(CatalogStore::class),
            ),
            $requestedName === CatalogAuthorization::class => new CatalogAuthorization(),
            $requestedName === CatalogGovernanceService::class => new CatalogGovernanceService(
                $container->get(CatalogGovernanceStore::class),
                $container->get(CatalogAuthorization::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            $requestedName === CatalogSeedService::class => new CatalogSeedService(
                $container->get(CatalogStore::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            $requestedName === CatalogSearchHandler::class => new CatalogSearchHandler(
                $container->get(CatalogQueryService::class),
            ),
            $requestedName === CatalogCategoryHandler::class => new CatalogCategoryHandler(
                $container->get(CatalogQueryService::class),
            ),
            $requestedName === CatalogProductHandler::class => new CatalogProductHandler(
                $container->get(CatalogQueryService::class),
            ),
            str_starts_with($requestedName, 'catalog.governance.') => new CatalogGovernanceHandler(
                $container->get(CatalogGovernanceService::class),
                substr($requestedName, strlen('catalog.governance.')),
            ),
            $requestedName === CatalogSeedCommand::class => new CatalogSeedCommand(
                $container->get(CatalogSeedService::class),
            ),
            default => throw new \LogicException('Unsupported catalog service: ' . $requestedName),
        };
    }
}
