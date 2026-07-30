<?php

declare(strict_types=1);

namespace Providentia\Shopping\Infrastructure\Factory;

use Doctrine\DBAL\Connection;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingService;
use Providentia\Shopping\Application\ShoppingIntelligenceService;
use Providentia\Shopping\Application\ShoppingIntelligenceStore;
use Providentia\Shopping\Application\ShoppingStore;
use Providentia\Shopping\Domain\ConsumptionEstimator;
use Providentia\Shopping\Domain\PackOptimizer;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;
use Providentia\Shopping\Domain\SuggestionEngine;
use Providentia\Shopping\Http\ShoppingHandler;
use Providentia\Shopping\Http\ShoppingIntelligenceHandler;
use Providentia\Shopping\Infrastructure\Doctrine\DbalShoppingIntelligenceStore;
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
            $requestedName === DbalShoppingIntelligenceStore::class => new DbalShoppingIntelligenceStore(
                $container->get(Connection::class),
            ),
            $requestedName === LegacySuggestionPolicy::class => new LegacySuggestionPolicy(),
            $requestedName === ConsumptionEstimator::class => new ConsumptionEstimator(),
            $requestedName === SuggestionEngine::class => new SuggestionEngine(),
            $requestedName === PackOptimizer::class => new PackOptimizer(),
            $requestedName === ShoppingService::class => new ShoppingService(
                $container->get(ShoppingStore::class),
                $container->get(HomeAuthorization::class),
                $container->get(LegacySuggestionPolicy::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            $requestedName === ShoppingIntelligenceService::class => new ShoppingIntelligenceService(
                $container->get(ShoppingIntelligenceStore::class),
                $container->get(HomeAuthorization::class),
                $container->get(ConsumptionEstimator::class),
                $container->get(SuggestionEngine::class),
                $container->get(PackOptimizer::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
            ),
            str_starts_with($requestedName, 'shopping.intelligence.') => new ShoppingIntelligenceHandler(
                $container->get(ShoppingIntelligenceService::class),
                substr($requestedName, strlen('shopping.intelligence.')),
            ),
            str_starts_with($requestedName, 'shopping.') => new ShoppingHandler(
                $container->get(ShoppingService::class),
                substr($requestedName, strlen('shopping.')),
            ),
            default => throw new \LogicException('Unsupported shopping service: ' . $requestedName),
        };
    }
}
