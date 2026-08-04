<?php

declare(strict_types=1);

namespace Providentia\DataGovernance;

use Providentia\DataGovernance\Application\DataGovernanceService;
use Providentia\DataGovernance\Application\DataGovernanceStore;
use Providentia\DataGovernance\Application\DataGovernanceProcessor;
use Providentia\DataGovernance\Application\DataGovernanceDownloadService;
use Providentia\DataGovernance\Application\DataArtifactStorage;
use Providentia\DataGovernance\Application\DataExportGenerator;
use Providentia\DataGovernance\Application\DataErasureExecutor;
use Providentia\DataGovernance\Infrastructure\Artifact\EncryptedFilesystemDataArtifactStorage;
use Providentia\DataGovernance\Infrastructure\Doctrine\DbalJsonDataExportGenerator;
use Providentia\DataGovernance\Infrastructure\Doctrine\DbalDataErasureExecutor;
use Providentia\DataGovernance\Infrastructure\Cli\DataGovernanceProcessCommand;
use Providentia\DataGovernance\Infrastructure\Doctrine\DbalDataGovernanceStore;
use Providentia\DataGovernance\Infrastructure\Factory\DataGovernanceFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    DataGovernanceStore::class => DbalDataGovernanceStore::class,
                    DataArtifactStorage::class => EncryptedFilesystemDataArtifactStorage::class,
                    DataExportGenerator::class => DbalJsonDataExportGenerator::class,
                    DataErasureExecutor::class => DbalDataErasureExecutor::class,
                ],
                'factories' => [
                    DbalDataGovernanceStore::class => DataGovernanceFactory::class,
                    DataGovernanceService::class => DataGovernanceFactory::class,
                    DataGovernanceProcessor::class => DataGovernanceFactory::class,
                    DataGovernanceDownloadService::class => DataGovernanceFactory::class,
                    EncryptedFilesystemDataArtifactStorage::class => DataGovernanceFactory::class,
                    DbalJsonDataExportGenerator::class => DataGovernanceFactory::class,
                    DbalDataErasureExecutor::class => DataGovernanceFactory::class,
                    DataGovernanceProcessCommand::class => DataGovernanceFactory::class,
                    'data-governance.account.export' => DataGovernanceFactory::class,
                    'data-governance.account.erasure' => DataGovernanceFactory::class,
                    'data-governance.account.requests' => DataGovernanceFactory::class,
                    'data-governance.home.export' => DataGovernanceFactory::class,
                    'data-governance.home.erasure' => DataGovernanceFactory::class,
                    'data-governance.home.requests' => DataGovernanceFactory::class,
                    'data-governance.request.cancel' => DataGovernanceFactory::class,
                    'data-governance.request.download-token' => DataGovernanceFactory::class,
                    'data-governance.request.download' => DataGovernanceFactory::class,
                ],
            ],
            'laminas-cli' => [
                'commands' => [
                    'data-governance:process' => DataGovernanceProcessCommand::class,
                ],
            ],
        ];
    }
}
