<?php

declare(strict_types=1);

namespace Providentia\Administration;

use Providentia\Administration\Application\BaselineImportService;
use Providentia\Administration\Application\BaselineImportStore;
use Providentia\Administration\Application\OperatorAccountService;
use Providentia\Administration\Application\OperatorInventoryService;
use Providentia\Administration\Application\OperatorShoppingService;
use Providentia\Administration\Infrastructure\Factory\OperatorShoppingFactory;
use Providentia\Administration\Application\OperatorStockPreferenceService;
use Providentia\Administration\Http\OperatorStockPreferenceHandler;
use Providentia\Administration\Infrastructure\Factory\OperatorStockPreferenceFactory;
use Providentia\Administration\Infrastructure\Cli\BaselineImportCommand;
use Providentia\Administration\Infrastructure\Doctrine\DbalBaselineImportStore;
use Providentia\Administration\Infrastructure\Factory\AdministrationFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    BaselineImportStore::class => DbalBaselineImportStore::class,
                ],
                'factories' => [
                    DbalBaselineImportStore::class => AdministrationFactory::class,
                    BaselineImportService::class => AdministrationFactory::class,
                    BaselineImportCommand::class => AdministrationFactory::class,
                    OperatorAccountService::class => AdministrationFactory::class,
                    OperatorInventoryService::class => AdministrationFactory::class,
                    OperatorShoppingService::class => OperatorShoppingFactory::class,
                    'operator-shopping.list-create' => OperatorShoppingFactory::class,
                    'operator-shopping.list-update' => OperatorShoppingFactory::class,
                    'operator-shopping.line-create' => OperatorShoppingFactory::class,
                    'operator-shopping.line-update' => OperatorShoppingFactory::class,
                    'operator-shopping.line-check' => OperatorShoppingFactory::class,

                    OperatorStockPreferenceService::class => OperatorStockPreferenceFactory::class,
                    OperatorStockPreferenceHandler::class => OperatorStockPreferenceFactory::class,
                    'operator-inventory.location-create' => AdministrationFactory::class,
                    'operator-inventory.location-update' => AdministrationFactory::class,
                    'operator-inventory.store-create' => AdministrationFactory::class,
                    'operator-inventory.store-update' => AdministrationFactory::class,
                    'operator-inventory.product-create' => AdministrationFactory::class,
                    'operator-inventory.product-update' => AdministrationFactory::class,
                    'operator-inventory.category-create' => AdministrationFactory::class,
                    'operator-inventory.category-update' => AdministrationFactory::class,

                    'administration.operator-accounts-list' => AdministrationFactory::class,
                    'administration.operator-accounts-get' => AdministrationFactory::class,
                    'administration.operator-accounts-status' => AdministrationFactory::class,
                    'administration.operator-accounts-role-grant' => AdministrationFactory::class,
                    'administration.operator-accounts-role-revoke' => AdministrationFactory::class,
                ],
            ],
            'laminas-cli' => [
                'commands' => [
                    'baseline:import' => BaselineImportCommand::class,
                ],
            ],
        ];
    }
}
