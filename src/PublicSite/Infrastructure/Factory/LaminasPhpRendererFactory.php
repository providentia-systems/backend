<?php

declare(strict_types=1);

namespace Providentia\PublicSite\Infrastructure\Factory;

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
                $resolver->addPath($path);
            }
        }

        $renderer = new PhpRenderer();
        $renderer->setResolver($resolver);

        return $renderer;
    }
}
