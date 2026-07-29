<?php

declare(strict_types=1);

namespace Providentia\PublicSite\Infrastructure\Factory;

use Mezzio\Template\TemplateRendererInterface;
use Providentia\PublicSite\Http\HomePageHandler;
use Psr\Container\ContainerInterface;

final class HomePageHandlerFactory
{
    public function __invoke(ContainerInterface $container): HomePageHandler
    {
        /** @var array{app: array{version: string}} $config */
        $config = $container->get('config');

        return new HomePageHandler(
            $container->get(TemplateRendererInterface::class),
            $config['app']['version'],
        );
    }
}
