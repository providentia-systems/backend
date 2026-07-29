<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Interop\Queue\Context;
use Providentia\SharedKernel\Application\Async\QueueMetricsProbe;
use Providentia\SharedKernel\Infrastructure\Doctrine\DoctrineTransactionManager;
use Providentia\SharedKernel\Infrastructure\Doctrine\DoctrineFoundationRecordStore;
use Providentia\SharedKernel\Infrastructure\Queue\EnqueueAsyncMessageBus;
use Providentia\SharedKernel\Infrastructure\Queue\EnqueueQueueReadinessProbe;
use Providentia\SharedKernel\Infrastructure\Queue\RedisQueueMetricsProbe;
use Providentia\SharedKernel\Infrastructure\Doctrine\DoctrineDatabaseReadinessProbe;
use Providentia\SharedKernel\Infrastructure\RuntimeSystemInformationProvider;
use Psr\Container\ContainerInterface;

final class AdapterFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /** @var array{app: array{environment: string, version: string}, queue: array{dsn: string, name: string, required: bool}} $config */
        $config = $container->get('config');

        return match ($requestedName) {
            DoctrineTransactionManager::class => new DoctrineTransactionManager(
                $container->get(EntityManagerInterface::class),
            ),
            DoctrineFoundationRecordStore::class => new DoctrineFoundationRecordStore(
                $container->get(EntityManagerInterface::class),
            ),
            EnqueueAsyncMessageBus::class => new EnqueueAsyncMessageBus(
                $container->get(Context::class),
            ),
            DoctrineDatabaseReadinessProbe::class => new DoctrineDatabaseReadinessProbe(
                $container->get(Connection::class),
            ),
            EnqueueQueueReadinessProbe::class => new EnqueueQueueReadinessProbe(
                $container->get(QueueMetricsProbe::class),
                $config['queue']['required'],
            ),
            RedisQueueMetricsProbe::class => new RedisQueueMetricsProbe(
                $config['queue']['dsn'],
                $config['queue']['name'],
            ),
            RuntimeSystemInformationProvider::class => new RuntimeSystemInformationProvider(
                $container->get(Connection::class),
                $config['app']['environment'],
                $config['app']['version'],
                str_starts_with($config['queue']['dsn'], 'redis') ? 'redis-compatible' : 'unknown',
            ),
            default => throw new \LogicException('Unsupported adapter: ' . $requestedName),
        };
    }
}
