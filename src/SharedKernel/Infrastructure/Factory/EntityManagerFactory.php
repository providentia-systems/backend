<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Psr\Container\ContainerInterface;

final class EntityManagerFactory
{
    public function __invoke(ContainerInterface $container): EntityManager
    {
        /** @var array{app: array{debug: bool}} $config */
        $config = $container->get('config');
        $ormConfig = ORMSetup::createXMLMetadataConfiguration(
            [dirname(__DIR__) . '/Doctrine/Mapping'],
            $config['app']['debug'],
            dirname(__DIR__, 4) . '/var/doctrine-proxies',
        );

        return new EntityManager($container->get(Connection::class), $ormConfig);
    }
}
