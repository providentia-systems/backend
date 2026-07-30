<?php

declare(strict_types=1);

namespace Providentia\Synchronization;

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
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncStore;
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
                ],
                'factories' => [
                    DbalSyncStore::class => SynchronizationFactory::class,
                    CursorCodec::class => SynchronizationFactory::class,
                    PrivateNoteSyncEntityPolicy::class => SynchronizationFactory::class,
                    HomePreferenceSyncEntityPolicy::class => SynchronizationFactory::class,
                    SyncEntityPolicyRegistry::class => SynchronizationFactory::class,
                    SyncEnvelopeValidator::class => SynchronizationFactory::class,
                    SyncOperationValidator::class => SynchronizationFactory::class,
                    SyncRequestHasher::class => SynchronizationFactory::class,
                    SyncResultPresenter::class => SynchronizationFactory::class,
                    SynchronizationService::class => SynchronizationFactory::class,
                    'synchronization.push' => SynchronizationFactory::class,
                    'synchronization.pull' => SynchronizationFactory::class,
                    'synchronization.bootstrap' => SynchronizationFactory::class,
                ],
            ],
        ];
    }
}
