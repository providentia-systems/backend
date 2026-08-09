<?php

declare(strict_types=1);

namespace Providentia\PublicSite\Infrastructure\Factory;

use Laminas\View\HelperPluginManagerInterface;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\View\Resolver\AggregateResolver;
use Psr\Container\ContainerInterface;

final class LaminasPhpRendererFactory
{
    public function __invoke(ContainerInterface $container): PhpRenderer
    {
        $helpers = $container->get(HelperPluginManagerInterface::class);
        if (! $helpers instanceof HelperPluginManagerInterface) {
            throw new \RuntimeException('View helper plugin manager is unavailable.');
        }
        $resolver = $container->get(AggregateResolver::class);
        if (! $resolver instanceof AggregateResolver) {
            throw new \RuntimeException('Namespaced template resolver is unavailable.');
        }

        return new PhpRenderer($helpers, $resolver, true);
    }
}
