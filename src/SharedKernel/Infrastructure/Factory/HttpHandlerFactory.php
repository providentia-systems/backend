<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Factory;

use Providentia\SharedKernel\Application\Async\OutboxStore;
use Providentia\SharedKernel\Application\Async\QueueMetricsProbe;
use Providentia\SharedKernel\Application\ReadinessService;
use Providentia\SharedKernel\Application\SystemInformationProvider;
use Providentia\SharedKernel\Http\Health\LivenessHandler;
use Providentia\SharedKernel\Http\Health\ReadinessHandler;
use Providentia\SharedKernel\Http\MetricsHandler;
use Providentia\SharedKernel\Http\ProblemDetailsMiddleware;
use Providentia\SharedKernel\Http\SystemInfoHandler;
use Providentia\SharedKernel\Http\CorsMiddleware;
use Providentia\SharedKernel\Application\Health\SyncMetricsProbe;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class HttpHandlerFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /** @var array{app: array{debug: bool, environment: string, version: string}, queue: array{dsn: string}, http: array{allowed_origins: list<string>}, metrics: array{enabled: bool, credential_hash: string}} $config */
        $config = $container->get('config');

        return match ($requestedName) {
            LivenessHandler::class => new LivenessHandler(),
            ReadinessHandler::class => new ReadinessHandler($container->get(ReadinessService::class)),
            MetricsHandler::class => new MetricsHandler(
                $container->get(OutboxStore::class),
                $container->get(QueueMetricsProbe::class),
                $container->get(SyncMetricsProbe::class),
                $config['metrics']['enabled'],
                $config['metrics']['credential_hash'],
            ),
            ProblemDetailsMiddleware::class => new ProblemDetailsMiddleware(
                $config['app']['debug'],
                $container->get(LoggerInterface::class),
            ),
            SystemInfoHandler::class => new SystemInfoHandler(
                $container->get(SystemInformationProvider::class),
            ),
            CorsMiddleware::class => new CorsMiddleware($config['http']['allowed_origins']),
            default => throw new \LogicException('Unsupported HTTP service: ' . $requestedName),
        };
    }
}
