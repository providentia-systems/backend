<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EntityManagerFactory
{
    public function __invoke(ContainerInterface $container): EntityManager
    {
        /** @var array{app: array{debug: bool}} $config */
        $config = $container->get('config');
        // ORMSetup probes optional cache extensions in non-debug mode and assumes
        // Redis is available on localhost. The runtime uses a separate Redis
        // container, so keep ORM metadata caching explicitly process-local.
        $ormConfig = ORMSetup::createXMLMetadataConfiguration(
            [dirname(__DIR__) . '/Doctrine/Mapping'],
            $config['app']['debug'],
            dirname(__DIR__, 4) . '/var/doctrine-proxies',
            new ArrayAdapter(),
        );
        $ormConfig->enableNativeLazyObjects(true);

        return new EntityManager($container->get(Connection::class), $ormConfig);
    }
}
