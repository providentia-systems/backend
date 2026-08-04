<?php

declare(strict_types=1);

namespace Providentia\SharedKernel;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Interop\Queue\Context;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Providentia\SharedKernel\Application\Async\AsyncMessageBus;
use Providentia\SharedKernel\Application\Async\OutboxRelay;
use Providentia\SharedKernel\Application\Async\OutboxStore;
use Providentia\SharedKernel\Application\Async\QueueMetricsProbe;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\FoundationProofService;
use Providentia\SharedKernel\Application\FoundationRecordStore;
use Providentia\SharedKernel\Application\ReadinessService;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\Health\DatabaseReadinessProbe;
use Providentia\SharedKernel\Application\Health\QueueReadinessProbe;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\SystemInformationProvider;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Http\Health\LivenessHandler;
use Providentia\SharedKernel\Http\Health\ReadinessHandler;
use Providentia\SharedKernel\Http\MetricsHandler;
use Providentia\SharedKernel\Http\ProblemDetailsMiddleware;
use Providentia\SharedKernel\Http\RequestIdMiddleware;
use Providentia\SharedKernel\Http\SystemInfoHandler;
use Providentia\SharedKernel\Http\CorsMiddleware;
use Providentia\SharedKernel\Http\SecurityHeadersMiddleware;
use Providentia\SharedKernel\Infrastructure\Cli\FoundationProofCommand;
use Providentia\SharedKernel\Infrastructure\Cli\OutboxRelayCommand;
use Providentia\SharedKernel\Infrastructure\Cli\QueueConsumeCommand;
use Providentia\SharedKernel\Infrastructure\Clock\SystemClock;
use Providentia\SharedKernel\Infrastructure\Doctrine\DoctrineOutboxStore;
use Providentia\SharedKernel\Infrastructure\Doctrine\DoctrineFoundationRecordStore;
use Providentia\SharedKernel\Infrastructure\Doctrine\DoctrineTransactionManager;
use Providentia\SharedKernel\Infrastructure\Factory\AdapterFactory;
use Providentia\SharedKernel\Infrastructure\Factory\ApplicationServiceFactory;
use Providentia\SharedKernel\Infrastructure\Factory\CliCommandFactory;
use Providentia\SharedKernel\Infrastructure\Factory\ConnectionFactory;
use Providentia\SharedKernel\Infrastructure\Factory\EntityManagerFactory;
use Providentia\SharedKernel\Infrastructure\Factory\HttpHandlerFactory;
use Providentia\SharedKernel\Infrastructure\Factory\QueueContextFactory;
use Providentia\SharedKernel\Infrastructure\Queue\EnqueueAsyncMessageBus;
use Providentia\SharedKernel\Infrastructure\Queue\EnqueueQueueReadinessProbe;
use Providentia\SharedKernel\Infrastructure\Queue\RedisQueueMetricsProbe;
use Providentia\SharedKernel\Infrastructure\RuntimeSystemInformationProvider;
use Providentia\SharedKernel\Infrastructure\Identifier\RamseyUuidGenerator;
use Providentia\SharedKernel\Infrastructure\Identifier\NativeSecureTokenGenerator;
use Providentia\SharedKernel\Infrastructure\Doctrine\DoctrineDatabaseReadinessProbe;
use Providentia\SharedKernel\Infrastructure\Logging\StderrJsonLogger;
use Psr\Log\LoggerInterface;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    AsyncMessageBus::class => EnqueueAsyncMessageBus::class,
                    Clock::class => SystemClock::class,
                    EntityManagerInterface::class => EntityManager::class,
                    FoundationRecordStore::class => DoctrineFoundationRecordStore::class,
                    OutboxStore::class => DoctrineOutboxStore::class,
                    TransactionManager::class => DoctrineTransactionManager::class,
                    DatabaseReadinessProbe::class => DoctrineDatabaseReadinessProbe::class,
                    QueueReadinessProbe::class => EnqueueQueueReadinessProbe::class,
                    QueueMetricsProbe::class => RedisQueueMetricsProbe::class,
                    SystemInformationProvider::class => RuntimeSystemInformationProvider::class,
                    SecureTokenGenerator::class => NativeSecureTokenGenerator::class,
                    UuidGenerator::class => RamseyUuidGenerator::class,
                    LoggerInterface::class => StderrJsonLogger::class,
                ],
                'factories' => [
                    Connection::class => ConnectionFactory::class,
                    EntityManager::class => EntityManagerFactory::class,
                    Context::class => QueueContextFactory::class,
                    SystemClock::class => InvokableFactory::class,
                    RamseyUuidGenerator::class => InvokableFactory::class,
                    NativeSecureTokenGenerator::class => InvokableFactory::class,
                    StderrJsonLogger::class => InvokableFactory::class,
                    EnqueueAsyncMessageBus::class => AdapterFactory::class,
                    DoctrineFoundationRecordStore::class => AdapterFactory::class,
                    DoctrineTransactionManager::class => AdapterFactory::class,
                    DoctrineDatabaseReadinessProbe::class => AdapterFactory::class,
                    EnqueueQueueReadinessProbe::class => AdapterFactory::class,
                    RedisQueueMetricsProbe::class => AdapterFactory::class,
                    RuntimeSystemInformationProvider::class => AdapterFactory::class,
                    DoctrineOutboxStore::class => ApplicationServiceFactory::class,
                    OutboxRelay::class => ApplicationServiceFactory::class,
                    ReadinessService::class => ApplicationServiceFactory::class,
                    FoundationProofService::class => ApplicationServiceFactory::class,
                    LivenessHandler::class => HttpHandlerFactory::class,
                    ReadinessHandler::class => HttpHandlerFactory::class,
                    MetricsHandler::class => HttpHandlerFactory::class,
                    SystemInfoHandler::class => HttpHandlerFactory::class,
                    ProblemDetailsMiddleware::class => HttpHandlerFactory::class,
                    RequestIdMiddleware::class => InvokableFactory::class,
                    CorsMiddleware::class => HttpHandlerFactory::class,
                    SecurityHeadersMiddleware::class => InvokableFactory::class,
                    FoundationProofCommand::class => CliCommandFactory::class,
                    OutboxRelayCommand::class => CliCommandFactory::class,
                    QueueConsumeCommand::class => CliCommandFactory::class,
                ],
            ],
            'laminas-cli' => [
                'commands' => [
                    'foundation:prove' => FoundationProofCommand::class,
                    'outbox:relay' => OutboxRelayCommand::class,
                    'queue:consume' => QueueConsumeCommand::class,
                ],
            ],
        ];
    }
}
