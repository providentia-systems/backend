<?php

declare(strict_types=1);

namespace Providentia\Catalog;

use Providentia\Catalog\Application\CatalogQueryService;
use Providentia\Catalog\Application\CatalogSeedService;
use Providentia\Catalog\Application\CatalogStore;
use Providentia\Catalog\Application\CatalogAuthorization;
use Providentia\Catalog\Application\CatalogGovernanceService;
use Providentia\Catalog\Application\CatalogGovernanceStore;
use Providentia\Catalog\Http\CatalogProductHandler;
use Providentia\Catalog\Http\CatalogSearchHandler;
use Providentia\Catalog\Infrastructure\Cli\CatalogSeedCommand;
use Providentia\Catalog\Infrastructure\Cli\CatalogRoleCommand;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogGovernanceStore;
use Providentia\Catalog\Infrastructure\Doctrine\DbalCatalogStore;
use Providentia\Catalog\Infrastructure\Factory\CatalogFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    CatalogStore::class => DbalCatalogStore::class,
                    CatalogGovernanceStore::class => DbalCatalogGovernanceStore::class,
                ],
                'factories' => [
                    DbalCatalogStore::class => CatalogFactory::class,
                    DbalCatalogGovernanceStore::class => CatalogFactory::class,
                    CatalogQueryService::class => CatalogFactory::class,
                    CatalogSeedService::class => CatalogFactory::class,
                    CatalogAuthorization::class => CatalogFactory::class,
                    CatalogGovernanceService::class => CatalogFactory::class,
                    CatalogSearchHandler::class => CatalogFactory::class,
                    CatalogProductHandler::class => CatalogFactory::class,
                    'catalog.governance.proposals.submit' => CatalogFactory::class,
                    'catalog.governance.workbench' => CatalogFactory::class,
                    'catalog.governance.proposals.decision' => CatalogFactory::class,
                    'catalog.governance.conflicts.keep' => CatalogFactory::class,
                    'catalog.governance.icons.put' => CatalogFactory::class,
                    'catalog.governance.merges.preview' => CatalogFactory::class,
                    'catalog.governance.merges.apply' => CatalogFactory::class,
                    'catalog.governance.merges.reverse' => CatalogFactory::class,
                    CatalogSeedCommand::class => CatalogFactory::class,
                    CatalogRoleCommand::class => CatalogFactory::class,
                ],
            ],
            'laminas-cli' => [
                'commands' => [
                    'catalog:seed' => CatalogSeedCommand::class,
                    'catalog:role' => CatalogRoleCommand::class,
                ],
            ],
        ];
    }
}
