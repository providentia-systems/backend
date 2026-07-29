<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Factory;

use Providentia\SharedKernel\Application\Async\OutboxRelay;
use Providentia\SharedKernel\Application\FoundationProofService;
use Providentia\SharedKernel\Infrastructure\Cli\FoundationProofCommand;
use Providentia\SharedKernel\Infrastructure\Cli\OutboxRelayCommand;
use Providentia\SharedKernel\Infrastructure\Cli\QueueConsumeCommand;
use Doctrine\DBAL\Connection;
use Interop\Queue\Context;
use Psr\Container\ContainerInterface;

final class CliCommandFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        /** @var array{queue: array{name: string}} $config */
        $config = $container->get('config');

        return match ($requestedName) {
            FoundationProofCommand::class => new FoundationProofCommand(
                $container->get(FoundationProofService::class),
            ),
            OutboxRelayCommand::class => new OutboxRelayCommand(
                $container->get(OutboxRelay::class),
            ),
            QueueConsumeCommand::class => new QueueConsumeCommand(
                $container->get(Context::class),
                $container->get(Connection::class),
                $config['queue']['name'],
            ),
            default => throw new \LogicException('Unsupported command: ' . $requestedName),
        };
    }
}
