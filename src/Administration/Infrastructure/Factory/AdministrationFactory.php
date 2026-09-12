<?php

declare(strict_types=1);

namespace Providentia\Administration\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Administration\Application\BaselineImportService;
use Providentia\Administration\Application\BaselineImportStore;
use Providentia\Administration\Application\OperatorAccountService;
use Providentia\Administration\Application\OperatorInventoryAuthorization;
use Providentia\Administration\Application\OperatorInventoryService;
use Providentia\Administration\Application\OperatorWorkspaceStore;
use Providentia\Administration\Http\OperatorInventoryHandler;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\Inventory\Application\InventoryMovementGateway;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Purchasing\Application\PurchasingStore;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\Administration\Http\OperatorAccountHandler;
use Providentia\Administration\Infrastructure\Cli\BaselineImportCommand;
use Providentia\Administration\Infrastructure\Doctrine\DbalBaselineImportStore;
use Providentia\Billing\Application\OperatorSubscriptionReader;
use Providentia\Home\Application\OperatorHomeAccessReader;
use Providentia\Identity\Application\OperatorAccountControl;
use Providentia\Identity\Application\OperatorIdentityDirectory;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class AdministrationFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        if (str_starts_with($requestedName, 'administration.operator-accounts-')) {
            return new OperatorAccountHandler(
                $container->get(OperatorAccountService::class),
                substr($requestedName, strlen('administration.operator-accounts-')),
            );
        }

        if (str_starts_with($requestedName, 'operator-inventory.')) {
            return new OperatorInventoryHandler(
                $container->get(OperatorInventoryService::class),
                substr($requestedName, strlen('operator-inventory.')),
            );
        }
        if ($requestedName === OperatorInventoryService::class) {
            $authorization = new OperatorInventoryAuthorization(
                $container->get(AccessService::class),
                $container->get(OperatorWorkspaceStore::class),
            );
            return new OperatorInventoryService(
                new InventoryService(
                    $container->get(InventoryStore::class),
                    $authorization,
                    $container->get(UuidGenerator::class),
                    $container->get(Clock::class),
                    $container->get(TransactionManager::class),
                    $container->get(ChangeFeedWriter::class),
                    $container->get(AccessService::class),
                ),
                $container->get(InventoryStore::class),
                new PurchasingService(
                    $container->get(PurchasingStore::class),
                    $container->get(InventoryMovementGateway::class),
                    $authorization,
                    $container->get(UuidGenerator::class),
                    $container->get(Clock::class),
                    $container->get(TransactionManager::class),
                    $container->get(ChangeFeedWriter::class),
                ),
                $container->get(PurchasingStore::class),
                $authorization,
                $container->get(AccessService::class),
                $container->get(AccessStore::class),
                $container->get(TransactionManager::class),
            );
        }

        return match ($requestedName) {
            DbalBaselineImportStore::class => new DbalBaselineImportStore(
                $container->get(Connection::class),
                $container->get(UuidGenerator::class),
            ),
            BaselineImportService::class => new BaselineImportService(
                $container->get(BaselineImportStore::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            BaselineImportCommand::class => new BaselineImportCommand(
                $container->get(BaselineImportService::class),
            ),
            OperatorAccountService::class => new OperatorAccountService(
                $container->get(OperatorIdentityDirectory::class),
                $container->get(OperatorAccountControl::class),
                $container->get(OperatorHomeAccessReader::class),
                $container->get(OperatorSubscriptionReader::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
                $container->get(\Providentia\Identity\Application\AccountProfileStore::class),
            ),
            default => throw new \LogicException('Unsupported administration service: ' . $requestedName),
        };
    }
}
