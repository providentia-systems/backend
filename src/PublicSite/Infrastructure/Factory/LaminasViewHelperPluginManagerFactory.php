<?php

declare(strict_types=1);

namespace Providentia\PublicSite\Infrastructure\Factory;

use Laminas\ServiceManager\ServiceManager;
use Laminas\View\HelperPluginManager;
use Psr\Container\ContainerInterface;

final class LaminasViewHelperPluginManagerFactory
{
    public function __invoke(ContainerInterface $container): HelperPluginManager
    {
        return new HelperPluginManager(new ServiceManager());
    }
}
