<?php

declare(strict_types=1);

namespace Providentia\Synchronization;

use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\HomePreferenceSyncEntityPolicy;
use Providentia\Synchronization\Application\PrivateNoteSyncEntityPolicy;
use Providentia\Synchronization\Application\PantrySyncCommandDispatcher;
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
use Providentia\Synchronization\Infrastructure\Doctrine\DbalChangeFeedWriter;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncStore;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncBackfillStore;
use Providentia\Synchronization\Infrastructure\Cli\SyncBackfillCommand;
use Providentia\Synchronization\Infrastructure\Cli\SyncCompactCommand;
use Providentia\Synchronization\Infrastructure\Factory\SynchronizationFactory;
use Providentia\SharedKernel\Application\Health\SyncMetricsProbe;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    SyncStore::class => DbalSyncStore::class,
                    SyncMetricsProbe::class => DbalSyncStore::class,
                    SyncCommandDispatcher::class => PantrySyncCommandDispatcher::class,
                    ChangeFeedWriter::class => DbalChangeFeedWriter::class,
                    SyncBackfillStore::class => DbalSyncBackfillStore::class,
                ],
                'factories' => [
                    DbalSyncStore::class => SynchronizationFactory::class,
                    DbalChangeFeedWriter::class => SynchronizationFactory::class,
                    DbalSyncBackfillStore::class => SynchronizationFactory::class,
                    CursorCodec::class => SynchronizationFactory::class,
                    SnapshotCursorCodec::class => SynchronizationFactory::class,
                    SyncCommandValidator::class => SynchronizationFactory::class,
                    SyncCommandHasher::class => SynchronizationFactory::class,
                    PantrySyncCommandDispatcher::class => SynchronizationFactory::class,
                    PrivateNoteSyncEntityPolicy::class => SynchronizationFactory::class,
                    HomePreferenceSyncEntityPolicy::class => SynchronizationFactory::class,
                    SyncEntityPolicyRegistry::class => SynchronizationFactory::class,
                    SyncEnvelopeValidator::class => SynchronizationFactory::class,
                    SyncOperationValidator::class => SynchronizationFactory::class,
                    SyncRequestHasher::class => SynchronizationFactory::class,
                    SyncResultPresenter::class => SynchronizationFactory::class,
                    SynchronizationService::class => SynchronizationFactory::class,
                    SyncBackfillService::class => SynchronizationFactory::class,
                    'synchronization.push' => SynchronizationFactory::class,
                    'synchronization.pull' => SynchronizationFactory::class,
                    'synchronization.bootstrap' => SynchronizationFactory::class,
                    'synchronization.operation-status' => SynchronizationFactory::class,
                    SyncCompactCommand::class => SynchronizationFactory::class,
                    SyncBackfillCommand::class => SynchronizationFactory::class,
                ],
            ],
            'laminas-cli' => [
                'commands' => [
                    'sync:compact' => SyncCompactCommand::class,
                    'sync:backfill' => SyncBackfillCommand::class,
                ],
            ],
        ];
    }
}
