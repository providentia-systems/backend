<?php

declare(strict_types=1);

namespace Providentia\Reporting\Infrastructure\Factory;

use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomeAuditRecorder;
use Providentia\Inventory\Application\InventorySummaryReader;
use Providentia\Inventory\Application\InventoryAnalyticsReader;
use Providentia\Purchasing\Application\PurchaseSummaryReader;
use Providentia\Purchasing\Application\PurchaseAnalyticsReader;
use Providentia\Reporting\Application\DashboardService;
use Providentia\Reporting\Application\HomeReportService;
use Providentia\Reporting\Http\DashboardHandler;
use Providentia\Reporting\Http\HomeReportHandler;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingIntelligenceReader;
use Providentia\Shopping\Application\ShoppingSummaryReader;
use Psr\Container\ContainerInterface;

final class ReportingFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        return match (true) {
            $requestedName === DashboardService::class => new DashboardService(
                $container->get(HomeAuthorization::class),
                $container->get(InventorySummaryReader::class),
                $container->get(PurchaseSummaryReader::class),
                $container->get(ShoppingSummaryReader::class),
            ),
            $requestedName === DashboardHandler::class => new DashboardHandler(
                $container->get(DashboardService::class),
            ),
            $requestedName === HomeReportService::class => new HomeReportService(
                $container->get(HomeAuthorization::class),
                $container->get(InventoryAnalyticsReader::class),
                $container->get(PurchaseAnalyticsReader::class),
                $container->get(ShoppingIntelligenceReader::class),
                $container->get(HomeAuditRecorder::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
            ),
            str_starts_with($requestedName, 'reporting.home.') => new HomeReportHandler(
                $container->get(HomeReportService::class),
                substr($requestedName, strlen('reporting.home.')),
            ),
            default => throw new \LogicException('Unsupported reporting service: ' . $requestedName),
        };
    }
}
