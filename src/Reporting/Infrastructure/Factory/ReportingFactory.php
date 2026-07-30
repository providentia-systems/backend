<?php

declare(strict_types=1);

namespace Providentia\Reporting\Infrastructure\Factory;

use Providentia\Home\Application\HomeAuthorization;
use Providentia\Inventory\Application\InventorySummaryReader;
use Providentia\Purchasing\Application\PurchaseSummaryReader;
use Providentia\Reporting\Application\DashboardService;
use Providentia\Reporting\Http\DashboardHandler;
use Providentia\Shopping\Application\ShoppingSummaryReader;
use Psr\Container\ContainerInterface;

final class ReportingFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        return match ($requestedName) {
            DashboardService::class => new DashboardService(
                $container->get(HomeAuthorization::class),
                $container->get(InventorySummaryReader::class),
                $container->get(PurchaseSummaryReader::class),
                $container->get(ShoppingSummaryReader::class),
            ),
            DashboardHandler::class => new DashboardHandler(
                $container->get(DashboardService::class),
            ),
            default => throw new \LogicException('Unsupported reporting service: ' . $requestedName),
        };
    }
}
