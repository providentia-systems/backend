<?php

declare(strict_types=1);

namespace Providentia\PublicSite\Infrastructure\Factory;

use Laminas\View\HelperPluginManagerInterface;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\View\Resolver\TemplatePathStack;
use Psr\Container\ContainerInterface;

final class LaminasPhpRendererFactory
{
    public function __invoke(ContainerInterface $container): PhpRenderer
    {
        /** @var array{templates: array{paths: array<string, list<string>>}} $config */
        $config = $container->get('config');
        $resolver = new TemplatePathStack();

        foreach ($config['templates']['paths'] as $paths) {
            foreach ($paths as $path) {
                if ($path === '') {
                    throw new \InvalidArgumentException('Template paths must not be empty.');
                }

                $resolver->addPath($path);
            }
        }

        $helpers = $container->get(HelperPluginManagerInterface::class);
        if (! $helpers instanceof HelperPluginManagerInterface) {
            throw new \RuntimeException('View helper plugin manager is unavailable.');
        }

        return new PhpRenderer($helpers, $resolver, true);
    }
}
