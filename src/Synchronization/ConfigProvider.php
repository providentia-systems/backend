<?php

declare(strict_types=1);

namespace Providentia\Synchronization;

use Providentia\Synchronization\Application\CursorCodec;
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
                    SynchronizationService::class => SynchronizationFactory::class,
                    'synchronization.push' => SynchronizationFactory::class,
                    'synchronization.pull' => SynchronizationFactory::class,
                    'synchronization.bootstrap' => SynchronizationFactory::class,
                ],
            ],
        ];
    }
}
