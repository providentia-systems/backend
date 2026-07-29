<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\SynchronizationService;
use Providentia\Synchronization\Application\SyncStore;
use Providentia\Synchronization\Http\SynchronizationHandler;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncStore;
use Psr\Container\ContainerInterface;

final class SynchronizationFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /** @var array{synchronization: array{cursor_secret: string, cursor_ttl_seconds: int, max_batch_operations: int, max_payload_bytes: int, page_size: int}} $config */
        $config = $container->get('config');
        $sync = $config['synchronization'];

        return match (true) {
            $requestedName === DbalSyncStore::class => new DbalSyncStore(
                $container->get(Connection::class),
                $container->get(UuidGenerator::class),
            ),
            $requestedName === CursorCodec::class => new CursorCodec(
                $sync['cursor_secret'],
                $container->get(Clock::class),
                $sync['cursor_ttl_seconds'],
            ),
            $requestedName === SynchronizationService::class => new SynchronizationService(
                $container->get(SyncStore::class),
                $container->get(CursorCodec::class),
                $container->get(HomeAuthorization::class),
                $container->get(Clock::class),
                $sync['max_batch_operations'],
                $sync['max_payload_bytes'],
                $sync['page_size'],
            ),
            str_starts_with($requestedName, 'synchronization.') => new SynchronizationHandler(
                $container->get(SynchronizationService::class),
                substr($requestedName, strlen('synchronization.')),
            ),
            default => throw new \LogicException('Unsupported synchronization service: ' . $requestedName),
        };
    }
}
