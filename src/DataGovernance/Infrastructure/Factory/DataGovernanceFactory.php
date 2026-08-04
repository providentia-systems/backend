<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
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
use Providentia\DataGovernance\Http\DataGovernanceHandler;
use Providentia\DataGovernance\Infrastructure\Doctrine\DbalDataGovernanceStore;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Identity\Application\CredentialHasher;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Psr\Container\ContainerInterface;

final class DataGovernanceFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /** @var array{data_governance: array{artifact_root: string, artifact_kek: string, page_size: int}} $config */
        $config = $container->get('config');

        return match (true) {
            $requestedName === DbalDataGovernanceStore::class => new DbalDataGovernanceStore(
                $container->get(Connection::class),
            ),
            $requestedName === DataGovernanceService::class => new DataGovernanceService(
                $container->get(DataGovernanceStore::class),
                $container->get(HomeAuthorization::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            $requestedName === DataGovernanceProcessor::class => new DataGovernanceProcessor(
                $container->get(DataGovernanceStore::class),
                $container->get(Clock::class),
                $container->get(DataExportGenerator::class),
                $container->get(DataArtifactStorage::class),
                $container->get(DataErasureExecutor::class),
            ),
            $requestedName === DataGovernanceDownloadService::class => new DataGovernanceDownloadService(
                $container->get(DataGovernanceStore::class),
                $container->get(HomeAuthorization::class),
                $container->get(DataArtifactStorage::class),
                $container->get(CredentialHasher::class),
                $container->get(SecureTokenGenerator::class),
                $container->get(Clock::class),
            ),
            $requestedName === EncryptedFilesystemDataArtifactStorage::class =>
                new EncryptedFilesystemDataArtifactStorage(
                    $config['data_governance']['artifact_root'],
                    $config['data_governance']['artifact_kek'],
                ),
            $requestedName === DbalJsonDataExportGenerator::class => new DbalJsonDataExportGenerator(
                $container->get(Connection::class),
                $config['data_governance']['page_size'],
            ),
            $requestedName === DbalDataErasureExecutor::class => new DbalDataErasureExecutor(
                $container->get(Connection::class),
            ),
            $requestedName === DataGovernanceProcessCommand::class => new DataGovernanceProcessCommand(
                $container->get(DataGovernanceProcessor::class),
            ),
            str_starts_with($requestedName, 'data-governance.') => new DataGovernanceHandler(
                $container->get(DataGovernanceService::class),
                $container->get(DataGovernanceDownloadService::class),
                substr($requestedName, strlen('data-governance.')),
            ),
            default => throw new \LogicException('Unsupported data-governance service: ' . $requestedName),
        };
    }
}
