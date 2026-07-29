<?php

declare(strict_types=1);

namespace Providentia\PublicSite\Infrastructure\Factory;

use Laminas\ServiceManager\ServiceManager;
use Laminas\View\HelperPluginManager;
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

        return new PhpRenderer(
            new HelperPluginManager(new ServiceManager()),
            $resolver,
            true,
        );
    }
}
