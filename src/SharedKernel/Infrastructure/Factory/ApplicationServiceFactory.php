<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\SharedKernel\Application\Async\AsyncMessageBus;
use Providentia\SharedKernel\Application\Async\OutboxRelay;
use Providentia\SharedKernel\Application\Async\OutboxStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\FoundationProofService;
use Providentia\SharedKernel\Application\FoundationRecordStore;
use Providentia\SharedKernel\Application\ReadinessService;
use Providentia\SharedKernel\Application\Health\DatabaseReadinessProbe;
use Providentia\SharedKernel\Application\Health\QueueReadinessProbe;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Infrastructure\Doctrine\DoctrineOutboxStore;
use Psr\Container\ContainerInterface;

final class ApplicationServiceFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /** @var array{queue: array{required: bool, name: string, outbox_batch_size: int, outbox_max_attempts: int}} $config */
        $config = $container->get('config');

        return match ($requestedName) {
            DoctrineOutboxStore::class => new DoctrineOutboxStore($container->get(Connection::class)),
            OutboxRelay::class => new OutboxRelay(
                $container->get(OutboxStore::class),
                $container->get(AsyncMessageBus::class),
                $config['queue']['outbox_batch_size'],
                $config['queue']['outbox_max_attempts'],
            ),
            ReadinessService::class => new ReadinessService(
                $container->get(DatabaseReadinessProbe::class),
                $container->get(QueueReadinessProbe::class),
            ),
            FoundationProofService::class => new FoundationProofService(
                $container->get(FoundationRecordStore::class),
                $container->get(TransactionManager::class),
                $container->get(OutboxStore::class),
                $container->get(Clock::class),
            ),
            default => throw new \LogicException('Unsupported service: ' . $requestedName),
        };
    }
}
