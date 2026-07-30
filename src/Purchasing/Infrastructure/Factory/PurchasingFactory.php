<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Inventory\Application\InventoryMovementGateway;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Purchasing\Application\PurchasingStore;
use Providentia\Purchasing\Http\PurchasingHandler;
use Providentia\Purchasing\Infrastructure\Doctrine\DbalPurchasingStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class PurchasingFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        return match (true) {
            $requestedName === DbalPurchasingStore::class => new DbalPurchasingStore(
                $container->get(Connection::class),
            ),
            $requestedName === PurchasingService::class => new PurchasingService(
                $container->get(PurchasingStore::class),
                $container->get(InventoryMovementGateway::class),
                $container->get(HomeAuthorization::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            str_starts_with($requestedName, 'purchasing.') => new PurchasingHandler(
                $container->get(PurchasingService::class),
                substr($requestedName, strlen('purchasing.')),
            ),
            default => throw new \LogicException('Unsupported purchasing service: ' . $requestedName),
        };
    }
}
