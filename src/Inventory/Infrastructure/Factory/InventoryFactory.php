<?php

declare(strict_types=1);

namespace Providentia\Inventory\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\Inventory\Http\InventoryHandler;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;
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
            $requestedName === DbalInventoryStore::class => new DbalInventoryStore(
                $container->get(Connection::class),
            ),
            $requestedName === InventoryService::class => new InventoryService(
                $container->get(InventoryStore::class),
                $container->get(HomeAuthorization::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
                $container->get(ChangeFeedWriter::class),
            ),
            str_starts_with($requestedName, 'inventory.') => new InventoryHandler(
                $container->get(InventoryService::class),
                substr($requestedName, strlen('inventory.')),
            ),
            default => throw new \LogicException('Unsupported inventory service: ' . $requestedName),
        };
    }
}
