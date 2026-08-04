<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogContributionService;
use Providentia\Catalog\Application\CatalogContributionStore;
use Providentia\Catalog\Http\CatalogContributionHandler;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogContributionStore;
use Providentia\Home\Application\HomeAuditRecorder;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class CatalogContributionFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        return match (true) {
            $requestedName === DbalCatalogContributionStore::class => new DbalCatalogContributionStore(
                $container->get(Connection::class),
            ),
            $requestedName === CatalogContributionService::class => new CatalogContributionService(
                $container->get(CatalogContributionStore::class),
                $container->get(HomeAuthorization::class),
                $container->get(CatalogAuthorization::class),
                $container->get(HomeAuditRecorder::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            str_starts_with($requestedName, 'catalog.contributions.') => new CatalogContributionHandler(
                $container->get(CatalogContributionService::class),
                substr($requestedName, strlen('catalog.contributions.')),
            ),
            default => throw new \LogicException('Unsupported catalog contribution service: ' . $requestedName),
        };
    }
}
