<?php

declare(strict_types=1);

namespace Providentia\Catalog;

use Providentia\Catalog\Application\CatalogQueryService;
use Providentia\Catalog\Application\CatalogSeedService;
use Providentia\Catalog\Application\CatalogStore;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogGovernanceService;
use Providentia\Catalog\Application\CatalogGovernanceStore;
use Providentia\Catalog\Application\CatalogContributionPromotionService;
use Providentia\Catalog\Application\CatalogContributionService;
use Providentia\Catalog\Application\CatalogContributionImageService;
use Providentia\Catalog\Application\CatalogContributionImageStore;
use Providentia\Catalog\Application\CatalogContributionStore;
use Providentia\Catalog\Application\CatalogImageCipher;
use Providentia\Catalog\Application\CatalogImageSanitizer;
use Providentia\Catalog\Application\CatalogIconPublisher;
use Providentia\Catalog\Application\CatalogImportService;
use Providentia\Catalog\Application\CatalogImportStore;
use Providentia\Catalog\Application\PublishedCategoryReader;
use Providentia\Catalog\Application\PublishedPackReader;
use Providentia\Catalog\Http\CatalogCategoryHandler;
use Providentia\Catalog\Http\CatalogContributionPromotionHandler;
use Providentia\Catalog\Http\CatalogProductHandler;
use Providentia\Catalog\Http\CatalogSearchHandler;
use Providentia\Catalog\Infrastructure\Cli\CatalogSeedCommand;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogGovernanceStore;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogStore;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogContributionStore;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogContributionImageStore;
use Providentia\Catalog\Infrastructure\Image\GdCatalogImageSanitizer;
use Providentia\Catalog\Infrastructure\Security\SodiumCatalogImageCipher;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogImportStore;
use Providentia\Catalog\Infrastructure\Factory\CatalogContributionFactory;
use Providentia\Catalog\Infrastructure\Factory\CatalogFactory;
use Providentia\Catalog\Infrastructure\Factory\CatalogImportFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    CatalogStore::class => DbalCatalogStore::class,
                    PublishedCategoryReader::class => DbalCatalogStore::class,
                    PublishedPackReader::class => DbalCatalogStore::class,
                    CatalogGovernanceStore::class => DbalCatalogGovernanceStore::class,
                    CatalogIconPublisher::class => DbalCatalogGovernanceStore::class,
                    CatalogContributionStore::class => DbalCatalogContributionStore::class,
                    CatalogContributionImageStore::class => DbalCatalogContributionImageStore::class,
                    CatalogImageSanitizer::class => GdCatalogImageSanitizer::class,
                    CatalogImageCipher::class => SodiumCatalogImageCipher::class,
                    CatalogImportStore::class => DbalCatalogImportStore::class,
                ],
                'factories' => [
                    DbalCatalogStore::class => CatalogFactory::class,
                    DbalCatalogGovernanceStore::class => CatalogFactory::class,
                    DbalCatalogContributionStore::class => CatalogContributionFactory::class,
                    DbalCatalogContributionImageStore::class => CatalogContributionFactory::class,
                    GdCatalogImageSanitizer::class => CatalogContributionFactory::class,
                    SodiumCatalogImageCipher::class => CatalogContributionFactory::class,
                    CatalogContributionPromotionService::class => CatalogContributionFactory::class,
                    CatalogContributionService::class => CatalogContributionFactory::class,
                    CatalogContributionImageService::class => CatalogContributionFactory::class,
                    DbalCatalogImportStore::class => CatalogImportFactory::class,
                    CatalogImportService::class => CatalogImportFactory::class,
                    CatalogQueryService::class => CatalogFactory::class,
                    CatalogSeedService::class => CatalogFactory::class,
                    CatalogAuthorization::class => CatalogFactory::class,
                    CatalogGovernanceService::class => CatalogFactory::class,
                    CatalogSearchHandler::class => CatalogFactory::class,
                    CatalogCategoryHandler::class => CatalogFactory::class,
                    CatalogContributionPromotionHandler::class => CatalogContributionFactory::class,
                    CatalogProductHandler::class => CatalogFactory::class,
                    'catalog.governance.proposals.submit' => CatalogFactory::class,
                    'catalog.governance.workbench' => CatalogFactory::class,
                    'catalog.governance.proposals.decision' => CatalogFactory::class,
                    'catalog.governance.conflicts.keep' => CatalogFactory::class,
                    'catalog.governance.icons.put' => CatalogFactory::class,
                    'catalog.governance.merges.preview' => CatalogFactory::class,
                    'catalog.governance.merges.apply' => CatalogFactory::class,
                    'catalog.governance.merges.reverse' => CatalogFactory::class,
                    'catalog.contributions.consent.get' => CatalogContributionFactory::class,
                    'catalog.contributions.consent.put' => CatalogContributionFactory::class,
                    'catalog.contributions.submit' => CatalogContributionFactory::class,
                    'catalog.contributions.list' => CatalogContributionFactory::class,
                    'catalog.contributions.published.list' => CatalogContributionFactory::class,
                    'catalog.contributions.review.list' => CatalogContributionFactory::class,
                    'catalog.contributions.review.decide' => CatalogContributionFactory::class,
                    'catalog.contribution-images.upload' => CatalogContributionFactory::class,
                    'catalog.contribution-images.preview' => CatalogContributionFactory::class,
                    'catalog.contribution-images.publication' => CatalogContributionFactory::class,
                    'catalog.contribution-images.content' => CatalogContributionFactory::class,
                    'catalog.imports.stage' => CatalogImportFactory::class,
                    'catalog.imports.get' => CatalogImportFactory::class,
                    'catalog.imports.confirm' => CatalogImportFactory::class,
                    CatalogSeedCommand::class => CatalogFactory::class,
                ],
            ],
            'laminas-cli' => [
                'commands' => [
                    'catalog:seed' => CatalogSeedCommand::class,
                ],
            ],
        ];
    }
}
