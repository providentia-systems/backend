<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\HomePreferenceSyncEntityPolicy;
use Providentia\Synchronization\Application\PrivateNoteSyncEntityPolicy;
use Providentia\Synchronization\Application\SyncEntityPolicyRegistry;
use Providentia\Synchronization\Application\SyncEnvelopeValidator;
use Providentia\Synchronization\Application\SyncOperationValidator;
use Providentia\Synchronization\Application\SyncRequestHasher;
use Providentia\Synchronization\Application\SyncResultPresenter;
use Providentia\Synchronization\Application\SynchronizationService;
use Providentia\Synchronization\Application\SyncStore;
use Providentia\Synchronization\Http\SynchronizationHandler;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncStore;
use Psr\Container\ContainerInterface;

final class SynchronizationFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /**
         * @var array{
         *     synchronization: array{
         *         cursor_secret: string,
         *         cursor_ttl_seconds: int,
         *         max_batch_operations: int,
         *         max_payload_bytes: int,
         *         page_size: int
         *     }
         * } $config
         */
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
            $requestedName === PrivateNoteSyncEntityPolicy::class => new PrivateNoteSyncEntityPolicy(),
            $requestedName === HomePreferenceSyncEntityPolicy::class => new HomePreferenceSyncEntityPolicy(),
            $requestedName === SyncEntityPolicyRegistry::class => new SyncEntityPolicyRegistry([
                $container->get(PrivateNoteSyncEntityPolicy::class),
                $container->get(HomePreferenceSyncEntityPolicy::class),
            ]),
            $requestedName === SyncEnvelopeValidator::class => new SyncEnvelopeValidator(
                $sync['max_batch_operations'],
            ),
            $requestedName === SyncOperationValidator::class => new SyncOperationValidator(
                $container->get(SyncEntityPolicyRegistry::class),
                $sync['max_payload_bytes'],
            ),
            $requestedName === SyncRequestHasher::class => new SyncRequestHasher(),
            $requestedName === SyncResultPresenter::class => new SyncResultPresenter(
                $container->get(CursorCodec::class),
            ),
            $requestedName === SynchronizationService::class => new SynchronizationService(
                $container->get(SyncStore::class),
                $container->get(CursorCodec::class),
                $container->get(HomeAuthorization::class),
                $container->get(Clock::class),
                $container->get(SyncEnvelopeValidator::class),
                $container->get(SyncOperationValidator::class),
                $container->get(SyncRequestHasher::class),
                $container->get(SyncResultPresenter::class),
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
