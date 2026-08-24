<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogAuditRecorder;
use Providentia\Catalog\Application\CatalogContributionImageService;
use Providentia\Catalog\Application\CatalogContributionImageStore;
use Providentia\Catalog\Application\CatalogContributionPromotionService;
use Providentia\Catalog\Application\CatalogContributionService;
use Providentia\Catalog\Application\CatalogContributionSourceReader;
use Providentia\Catalog\Application\CatalogContributionStore;
use Providentia\Catalog\Application\CatalogGovernanceService;
use Providentia\Catalog\Application\CatalogIconPublisher;
use Providentia\Catalog\Application\CatalogImageCipher;
use Providentia\Catalog\Application\CatalogImageSanitizer;
use Providentia\Catalog\Application\CatalogHomeAccess;
use Providentia\Catalog\Application\CatalogStore;
use Providentia\Catalog\Application\PublishedCategoryReader;
use Providentia\Catalog\Application\PublishedPackReader;
use Providentia\Catalog\Http\CatalogContributionImageHandler;
use Providentia\Catalog\Http\CatalogContributionPromotionHandler;
use Providentia\Catalog\Http\CatalogContributionHandler;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogContributionStore;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogContributionImageStore;
use Providentia\Catalog\Infrastructure\Image\GdCatalogImageSanitizer;
use Providentia\Catalog\Infrastructure\Security\SodiumCatalogImageCipher;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Psr\Container\ContainerInterface;

final class CatalogContributionFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /** @var array<string, mixed> $config */
        $config = $container->get('config');
        /** @var array<string, mixed> $imageConfig */
        $imageConfig = is_array($config['catalog_contribution_images'] ?? null)
            ? $config['catalog_contribution_images']
            : [];
        /** @var list<array{version: int, kek: string}> $previousImageKeys */
        $previousImageKeys = is_array($imageConfig['previous_keys'] ?? null)
            ? array_values($imageConfig['previous_keys'])
            : [];

        return match (true) {
            $requestedName === DbalCatalogContributionStore::class => new DbalCatalogContributionStore(
                $container->get(Connection::class),
            ),
            $requestedName === DbalCatalogContributionImageStore::class => new DbalCatalogContributionImageStore(
                $container->get(Connection::class),
            ),
            $requestedName === GdCatalogImageSanitizer::class => new GdCatalogImageSanitizer(),
            $requestedName === SodiumCatalogImageCipher::class => new SodiumCatalogImageCipher(
                (string) ($imageConfig['kek'] ?? ''),
                (int) ($imageConfig['key_version'] ?? 1),
                $previousImageKeys,
            ),
            $requestedName === CatalogContributionService::class => new CatalogContributionService(
                $container->get(CatalogContributionStore::class),
                $container->get(CatalogContributionImageStore::class),
                $container->get(CatalogContributionSourceReader::class),
                $container->get(PublishedPackReader::class),
                $container->get(CatalogHomeAccess::class),
                $container->get(CatalogAuthorization::class),
                $container->get(CatalogAuditRecorder::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            $requestedName === CatalogContributionImageService::class => new CatalogContributionImageService(
                $container->get(CatalogContributionStore::class),
                $container->get(CatalogContributionImageStore::class),
                $container->get(CatalogContributionSourceReader::class),
                $container->get(CatalogImageSanitizer::class),
                $container->get(CatalogImageCipher::class),
                $container->get(CatalogIconPublisher::class),
                $container->get(CatalogStore::class),
                $container->get(CatalogHomeAccess::class),
                $container->get(CatalogAuthorization::class),
                $container->get(CatalogAuditRecorder::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            $requestedName === CatalogContributionPromotionService::class => new CatalogContributionPromotionService(
                $container->get(CatalogContributionStore::class),
                $container->get(CatalogGovernanceService::class),
                $container->get(PublishedCategoryReader::class),
                $container->get(CatalogAuthorization::class),
                $container->get(CatalogAuditRecorder::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            $requestedName === CatalogContributionPromotionHandler::class => new CatalogContributionPromotionHandler(
                $container->get(CatalogContributionPromotionService::class),
            ),
            str_starts_with($requestedName, 'catalog.contribution-images.') => new CatalogContributionImageHandler(
                $container->get(CatalogContributionImageService::class),
                substr($requestedName, strlen('catalog.contribution-images.')),
                (int) ($imageConfig['max_upload_bytes'] ?? 5242880),
            ),
            str_starts_with($requestedName, 'catalog.contributions.') => new CatalogContributionHandler(
                $container->get(CatalogContributionService::class),
                substr($requestedName, strlen('catalog.contributions.')),
            ),
            default => throw new \LogicException('Unsupported catalog contribution service: ' . $requestedName),
        };
    }
}
