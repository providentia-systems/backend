<?php

declare(strict_types=1);

use Mezzio\Application;
use Providentia\PublicSite\Http\HomePageHandler;
use Providentia\SharedKernel\Http\Health\LivenessHandler;
use Providentia\SharedKernel\Http\Health\ReadinessHandler;
use Providentia\SharedKernel\Http\MetricsHandler;
use Providentia\SharedKernel\Http\SystemInfoHandler;

return static function (Application $app): void {
    $app->get('/', HomePageHandler::class, 'public.home');
    $app->get('/health/live', LivenessHandler::class, 'health.live');
    $app->get('/health/ready', ReadinessHandler::class, 'health.ready');
    $app->get('/api/v1/system/info', SystemInfoHandler::class, 'api.system.info');
    $app->get('/metrics', MetricsHandler::class, 'metrics');
};

