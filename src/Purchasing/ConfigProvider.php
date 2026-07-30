<?php

declare(strict_types=1);

namespace Providentia\Purchasing;

use Providentia\Purchasing\Application\PurchasingService;
use Providentia\Purchasing\Application\PurchasingStore;
use Providentia\Purchasing\Application\PurchaseSummaryReader;
use Providentia\Purchasing\Application\PurchaseAnalyticsReader;
use Providentia\Purchasing\Infrastructure\Doctrine\DbalPurchasingStore;
use Providentia\Purchasing\Infrastructure\Factory\PurchasingFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    PurchasingStore::class => DbalPurchasingStore::class,
                    PurchaseSummaryReader::class => DbalPurchasingStore::class,
                    PurchaseAnalyticsReader::class => DbalPurchasingStore::class,
                ],
                'factories' => [
                    DbalPurchasingStore::class => PurchasingFactory::class,
                    PurchasingService::class => PurchasingFactory::class,
                    'purchasing.history' => PurchasingFactory::class,
                    'purchasing.get' => PurchasingFactory::class,
                    'purchasing.summary' => PurchasingFactory::class,
                    'purchasing.stores.create' => PurchasingFactory::class,
                    'purchasing.create' => PurchasingFactory::class,
                    'purchasing.lines.create' => PurchasingFactory::class,
                    'purchasing.lines.approve' => PurchasingFactory::class,
                    'purchasing.commit' => PurchasingFactory::class,
                ],
            ],
        ];
    }
}
