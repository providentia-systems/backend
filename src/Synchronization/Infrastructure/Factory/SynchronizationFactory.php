<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingService;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\HomePreferenceSyncEntityPolicy;
use Providentia\Synchronization\Application\PantrySyncCommandDispatcher;
use Providentia\Synchronization\Application\PrivateNoteSyncEntityPolicy;
use Providentia\Synchronization\Application\SnapshotCursorCodec;
use Providentia\Synchronization\Application\SyncBackfillService;
use Providentia\Synchronization\Application\SyncBackfillStore;
use Providentia\Synchronization\Application\SyncCommandDispatcher;
use Providentia\Synchronization\Application\SyncCommandHasher;
use Providentia\Synchronization\Application\SyncCommandValidator;
use Providentia\Synchronization\Application\SyncEntityPolicyRegistry;
use Providentia\Synchronization\Application\SyncEnvelopeValidator;
use Providentia\Synchronization\Application\SyncOperationValidator;
use Providentia\Synchronization\Application\SyncRequestHasher;
use Providentia\Synchronization\Application\SyncResultPresenter;
use Providentia\Synchronization\Application\SynchronizationService;
use Providentia\Synchronization\Application\SyncStore;
use Providentia\Synchronization\Http\SynchronizationHandler;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalChangeFeedWriter;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncStore;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncBackfillStore;
use Providentia\Synchronization\Infrastructure\Cli\SyncBackfillCommand;
use Providentia\Synchronization\Infrastructure\Cli\SyncCompactCommand;
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
         *         page_size: int,
         *         offline_window_days: int,
         *         tombstone_retention_days: int
         *     }
         * } $config
         */
        $config = $container->get('config');
        $sync = $config['synchronization'];

        return match (true) {
            $requestedName === DbalChangeFeedWriter::class => new DbalChangeFeedWriter(
                $container->get(Connection::class),
                $container->get(UuidGenerator::class),
            ),
            $requestedName === DbalSyncBackfillStore::class => new DbalSyncBackfillStore(
                $container->get(Connection::class),
            ),
            $requestedName === DbalSyncStore::class => new DbalSyncStore(
                $container->get(Connection::class),
                $container->get(UuidGenerator::class),
                $sync['offline_window_days'],
                $sync['tombstone_retention_days'],
            ),
            $requestedName === CursorCodec::class => new CursorCodec(
                $sync['cursor_secret'],
                $container->get(Clock::class),
                $sync['cursor_ttl_seconds'],
            ),
            $requestedName === SnapshotCursorCodec::class => new SnapshotCursorCodec(
                $sync['cursor_secret'],
                $container->get(Clock::class),
                $sync['cursor_ttl_seconds'],
            ),
            $requestedName === SyncCommandValidator::class => new SyncCommandValidator(
                $sync['max_payload_bytes'],
            ),
            $requestedName === SyncCommandHasher::class => new SyncCommandHasher(),
            $requestedName === PantrySyncCommandDispatcher::class => new PantrySyncCommandDispatcher(
                $container->get(InventoryService::class),
                $container->get(PurchasingService::class),
                $container->get(ShoppingService::class),
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
                $container->get(SyncCommandValidator::class),
                $container->get(SyncCommandDispatcher::class),
                $container->get(SyncCommandHasher::class),
                $container->get(TransactionManager::class),
                $container->get(SnapshotCursorCodec::class),
            ),
            $requestedName === SyncBackfillService::class => new SyncBackfillService(
                $container->get(SyncBackfillStore::class),
                $container->get(ChangeFeedWriter::class),
                $container->get(TransactionManager::class),
            ),
            $requestedName === SyncBackfillCommand::class => new SyncBackfillCommand(
                $container->get(SyncBackfillService::class),
            ),
            $requestedName === SyncCompactCommand::class => new SyncCompactCommand(
                $container->get(SyncStore::class),
                $container->get(Clock::class),
            ),
            str_starts_with($requestedName, 'synchronization.') => new SynchronizationHandler(
                $container->get(SynchronizationService::class),
                substr($requestedName, strlen('synchronization.')),
            ),
            default => throw new \LogicException('Unsupported synchronization service: ' . $requestedName),
        };
    }
}
