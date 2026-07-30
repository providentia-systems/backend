<?php

declare(strict_types=1);

namespace Providentia\Administration\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Administration\Application\BaselineImportService;
use Providentia\Administration\Application\BaselineImportStore;
use Providentia\Administration\Infrastructure\Cli\BaselineImportCommand;
use Providentia\Administration\Infrastructure\Doctrine\DbalBaselineImportStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class AdministrationFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
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
            default => throw new \LogicException('Unsupported administration service: ' . $requestedName),
        };
    }
}
