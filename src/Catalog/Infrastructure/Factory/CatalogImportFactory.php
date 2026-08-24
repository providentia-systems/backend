<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Catalog\Application\CatalogImportHomeProductGateway;
use Providentia\Catalog\Application\CatalogImportService;
use Providentia\Catalog\Application\CatalogImportStore;
use Providentia\Catalog\Application\CatalogHomeAccess;
use Providentia\Catalog\Http\CatalogImportHandler;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogImportStore;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class CatalogImportFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        return match (true) {
            $requestedName === DbalCatalogImportStore::class => new DbalCatalogImportStore(
                $container->get(Connection::class),
                $container->get(CatalogImportHomeProductGateway::class),
            ),
            $requestedName === CatalogImportService::class => new CatalogImportService(
                $container->get(CatalogImportStore::class),
                $container->get(CatalogHomeAccess::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
                $container->get(ChangeFeedWriter::class),
            ),
            str_starts_with($requestedName, 'catalog.imports.') => new CatalogImportHandler(
                $container->get(CatalogImportService::class),
                substr($requestedName, strlen('catalog.imports.')),
            ),
            default => throw new \LogicException('Unsupported catalog import service: ' . $requestedName),
        };
    }
}
