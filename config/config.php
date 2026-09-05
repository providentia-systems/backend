<?php

declare(strict_types=1);

use Laminas\ConfigAggregator\ArrayProvider;
use Laminas\ConfigAggregator\ConfigAggregator;

$providers = [
    Mezzio\ConfigProvider::class,
    Mezzio\Router\ConfigProvider::class,
    Mezzio\Router\FastRouteRouter\ConfigProvider::class,
    Mezzio\Helper\ConfigProvider::class,
    Providentia\SharedKernel\ConfigProvider::class,
    Providentia\Identity\ConfigProvider::class,
    Providentia\Access\ConfigProvider::class,
    Providentia\Home\ConfigProvider::class,
    Providentia\Catalog\ConfigProvider::class,
    Providentia\DataGovernance\ConfigProvider::class,
    Providentia\Inventory\ConfigProvider::class,
    Providentia\Purchasing\ConfigProvider::class,
    Providentia\Shopping\ConfigProvider::class,
    Providentia\Synchronization\ConfigProvider::class,
    Providentia\AiIntegration\ConfigProvider::class,
    Providentia\Billing\ConfigProvider::class,
    Providentia\Administration\ConfigProvider::class,
    Providentia\Reporting\ConfigProvider::class,
    new ArrayProvider(require __DIR__ . '/autoload/global.php'),
    new ArrayProvider(require __DIR__ . '/autoload/local.php'),
];

$aggregator = new ConfigAggregator($providers);

return $aggregator->getMergedConfig();
