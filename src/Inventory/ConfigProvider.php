<?php

declare(strict_types=1);

namespace Providentia\Inventory;

use Providentia\Catalog\Application\CatalogContributionSourceReader;
use Providentia\Catalog\Application\CatalogImportHomeProductGateway;
use Providentia\Catalog\Application\CatalogMergeHomeProductGateway;
use Providentia\Inventory\Application\InventoryMovementGateway;
use Providentia\Inventory\Application\InventoryService;
use Providentia\Inventory\Application\InventoryAnalyticsReader;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\Inventory\Application\InventorySummaryReader;
use Providentia\Inventory\Infrastructure\Doctrine\DbalInventoryStore;
use Providentia\Inventory\Infrastructure\Doctrine\DbalCatalogContributionSourceReader;
use Providentia\Inventory\Infrastructure\Doctrine\DbalCatalogImportHomeProductGateway;
use Providentia\Inventory\Infrastructure\Doctrine\DbalCatalogMergeHomeProductGateway;
use Providentia\Inventory\Infrastructure\Factory\InventoryFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    InventoryStore::class => DbalInventoryStore::class,
                    InventorySummaryReader::class => DbalInventoryStore::class,
                    InventoryAnalyticsReader::class => DbalInventoryStore::class,
                    InventoryMovementGateway::class => InventoryService::class,
                    CatalogContributionSourceReader::class => DbalCatalogContributionSourceReader::class,
                    CatalogImportHomeProductGateway::class => DbalCatalogImportHomeProductGateway::class,
                    CatalogMergeHomeProductGateway::class => DbalCatalogMergeHomeProductGateway::class,
                ],
                'factories' => [
                    DbalInventoryStore::class => InventoryFactory::class,
                    DbalCatalogContributionSourceReader::class => InventoryFactory::class,
                    DbalCatalogImportHomeProductGateway::class => InventoryFactory::class,
                    DbalCatalogMergeHomeProductGateway::class => InventoryFactory::class,
                    InventoryService::class => InventoryFactory::class,
                    'inventory.locations.list' => InventoryFactory::class,
                    'inventory.locations.create' => InventoryFactory::class,
                    'inventory.categories.list' => InventoryFactory::class,
                    'inventory.categories.create' => InventoryFactory::class,
                    'inventory.categories.update' => InventoryFactory::class,
                    'inventory.items.list' => InventoryFactory::class,
                    'inventory.items.create' => InventoryFactory::class,
                    'inventory.items.update' => InventoryFactory::class,
                    'inventory.stock.list' => InventoryFactory::class,
                    'inventory.balances.list' => InventoryFactory::class,
                    'inventory.adjustments.create' => InventoryFactory::class,
                    'inventory.movements.list' => InventoryFactory::class,
                    'inventory.counts.list' => InventoryFactory::class,
                    'inventory.counts.create' => InventoryFactory::class,
                    'inventory.counts.get' => InventoryFactory::class,
                    'inventory.counts.line' => InventoryFactory::class,
                    'inventory.counts.close' => InventoryFactory::class,
                    'inventory.counts.cancel' => InventoryFactory::class,
                    'inventory.balances.rebuild' => InventoryFactory::class,
                ],
            ],
        ];
    }
}
