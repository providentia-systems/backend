<?php

declare(strict_types=1);

namespace Providentia\Inventory\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Inventory\Application\HomeProductIdentityRepairStore;
use Providentia\Inventory\Application\HomeProductIdentityReconciler;
use Providentia\Inventory\Infrastructure\Doctrine\DbalHomeProductIdentityRepairStore;
use Providentia\Inventory\Infrastructure\Doctrine\DbalHomeProductSyncNormalizer;
use Providentia\Inventory\Infrastructure\Cli\ReconcileHomeProductIdentitiesCommand as RepairIdentitiesCommand;
use Providentia\Synchronization\Application\SyncRepresentationNormalizer;
use Providentia\Home\Application\HomeStore;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\Inventory\Http\InventoryHandler;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;
use Providentia\Inventory\Infrastructure\Doctrine\DbalCatalogContributionSourceReader;
use Providentia\Inventory\Infrastructure\Doctrine\DbalCatalogImportHomeProductGateway;
use Providentia\Inventory\Infrastructure\Doctrine\DbalCatalogMergeHomeProductGateway;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class InventoryFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        return match (true) {
            $requestedName === DbalHomeProductSyncNormalizer::class => new DbalHomeProductSyncNormalizer(
                $container->get(Connection::class),
            ),
            $requestedName === DbalHomeProductIdentityRepairStore::class => new DbalHomeProductIdentityRepairStore(
                $container->get(Connection::class),
            ),
            $requestedName === HomeProductIdentityReconciler::class => new HomeProductIdentityReconciler(
                $container->get(HomeProductIdentityRepairStore::class),
                $container->get(HomeStore::class),
                $container->get(ChangeFeedWriter::class),
                $container->get(TransactionManager::class),
                $container->get(Clock::class),
            ),
            $requestedName === RepairIdentitiesCommand::class => new RepairIdentitiesCommand(
                $container->get(HomeProductIdentityReconciler::class),
            ),
            $requestedName === DbalInventoryStore::class => new DbalInventoryStore(
                $container->get(Connection::class),
            ),
            $requestedName === DbalCatalogContributionSourceReader::class => new DbalCatalogContributionSourceReader(
                $container->get(Connection::class),
            ),
            $requestedName === DbalCatalogImportHomeProductGateway::class => new DbalCatalogImportHomeProductGateway(
                $container->get(Connection::class),
                $container->get(\Providentia\Access\Application\AccessService::class),
            ),
            $requestedName === DbalCatalogMergeHomeProductGateway::class => new DbalCatalogMergeHomeProductGateway(
                $container->get(Connection::class),
            ),
            $requestedName === InventoryService::class => new InventoryService(
                $container->get(InventoryStore::class),
                $container->get(HomeAuthorization::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
                $container->get(ChangeFeedWriter::class),
                $container->get(\Providentia\Access\Application\AccessService::class),
            ),
            str_starts_with($requestedName, 'inventory.') => new InventoryHandler(
                $container->get(InventoryService::class),
                substr($requestedName, strlen('inventory.')),
            ),
            default => throw new \LogicException('Unsupported inventory service: ' . $requestedName),
        };
    }
}
