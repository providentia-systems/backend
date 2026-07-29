<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Factory;

use Enqueue\Redis\RedisConnectionFactory;
use Interop\Queue\Context;
use Psr\Container\ContainerInterface;

final class QueueContextFactory
{
    public function __invoke(ContainerInterface $container): Context
    {
        /** @var array{queue: array{dsn: string}} $config */
        $config = $container->get('config');

        return (new RedisConnectionFactory($config['queue']['dsn']))->createContext();
    }
}
