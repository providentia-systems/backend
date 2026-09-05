<?php

declare(strict_types=1);

namespace Providentia\Administration\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Administration\Application\BaselineImportService;
use Providentia\Administration\Application\BaselineImportStore;
use Providentia\Administration\Application\OperatorAccountService;
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
