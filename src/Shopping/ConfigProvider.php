<?php

declare(strict_types=1);

namespace Providentia\Shopping;

use Providentia\Shopping\Application\ShoppingService;
use Providentia\Shopping\Application\ShoppingStore;
use Providentia\Shopping\Application\ShoppingSummaryReader;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;
use Providentia\Shopping\Infrastructure\Doctrine\DbalShoppingStore;
use Providentia\Shopping\Infrastructure\Factory\ShoppingFactory;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'aliases' => [
                    ShoppingStore::class => DbalShoppingStore::class,
                    ShoppingSummaryReader::class => DbalShoppingStore::class,
                ],
                'factories' => [
                    DbalShoppingStore::class => ShoppingFactory::class,
                    LegacySuggestionPolicy::class => ShoppingFactory::class,
                    ShoppingService::class => ShoppingFactory::class,
                    'shopping.lists.list' => ShoppingFactory::class,
                    'shopping.lists.get' => ShoppingFactory::class,
                    'shopping.lists.create' => ShoppingFactory::class,
                    'shopping.lines.create' => ShoppingFactory::class,
                    'shopping.lines.check' => ShoppingFactory::class,
                    'shopping.suggestions' => ShoppingFactory::class,
                ],
            ],
        ];
    }
}
