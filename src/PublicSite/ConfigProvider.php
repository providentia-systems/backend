<?php

declare(strict_types=1);

namespace Providentia\PublicSite;

use Providentia\PublicSite\Http\HomePageHandler;
use Providentia\PublicSite\Infrastructure\Factory\HomePageHandlerFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'factories' => [
                    HomePageHandler::class => HomePageHandlerFactory::class,
                ],
            ],
        ];
    }
}
