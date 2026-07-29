<?php

declare(strict_types=1);

namespace Providentia\Catalog;

use Providentia\Catalog\Application\CatalogQueryService;
use Providentia\Catalog\Application\CatalogSeedService;
use Providentia\Catalog\Application\CatalogStore;
use Providentia\Catalog\Http\CatalogSearchHandler;
use Providentia\Catalog\Infrastructure\Cli\CatalogSeedCommand;
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
                ],
                'factories' => [
                    DbalCatalogStore::class => CatalogFactory::class,
                    CatalogQueryService::class => CatalogFactory::class,
                    CatalogSeedService::class => CatalogFactory::class,
                    CatalogSearchHandler::class => CatalogFactory::class,
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
