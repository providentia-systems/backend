<?php

declare(strict_types=1);

namespace Providentia\Shopping\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingService;
use Providentia\Shopping\Application\ShoppingStore;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;
use Providentia\Shopping\Http\ShoppingHandler;
use Providentia\Shopping\Infrastructure\Doctrine\DbalShoppingStore;
use Psr\Container\ContainerInterface;

final class ShoppingFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        return match (true) {
            $requestedName === DbalShoppingStore::class => new DbalShoppingStore(
                $container->get(Connection::class),
            ),
            $requestedName === LegacySuggestionPolicy::class => new LegacySuggestionPolicy(),
            $requestedName === ShoppingService::class => new ShoppingService(
                $container->get(ShoppingStore::class),
                $container->get(HomeAuthorization::class),
                $container->get(LegacySuggestionPolicy::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            str_starts_with($requestedName, 'shopping.') => new ShoppingHandler(
                $container->get(ShoppingService::class),
                substr($requestedName, strlen('shopping.')),
            ),
            default => throw new \LogicException('Unsupported shopping service: ' . $requestedName),
        };
    }
}
