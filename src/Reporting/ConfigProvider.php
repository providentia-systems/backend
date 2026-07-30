<?php

declare(strict_types=1);

namespace Providentia\Reporting;

use Providentia\Reporting\Application\DashboardService;
use Providentia\Reporting\Http\DashboardHandler;
use Providentia\Reporting\Infrastructure\Factory\ReportingFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'factories' => [
                    DashboardService::class => ReportingFactory::class,
                    DashboardHandler::class => ReportingFactory::class,
                ],
            ],
        ];
    }
}
