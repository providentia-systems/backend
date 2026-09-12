<?php

declare(strict_types=1);

namespace Providentia\Administration\Infrastructure\Factory;

use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Administration\Application\OperatorInventoryAuthorization;
use Providentia\Administration\Application\OperatorShoppingService;
use Providentia\Administration\Application\OperatorWorkspaceStore;
use Providentia\Administration\Http\OperatorShoppingHandler;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingService;
use Providentia\Shopping\Application\ShoppingStore;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;
use Providentia\Shopping\Application\ShoppingIntelligenceService;
use Providentia\Shopping\Application\ShoppingIntelligenceStore;
use Providentia\Shopping\Domain\ConsumptionEstimator;
use Providentia\Shopping\Domain\PackOptimizer;
use Providentia\Shopping\Domain\SuggestionEngine;
use Psr\Container\ContainerInterface;

final class OperatorShoppingFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        if (str_starts_with($requestedName, 'operator-shopping.')) {
            return new OperatorShoppingHandler(
                $container->get(OperatorShoppingService::class),
                substr($requestedName, strlen('operator-shopping.')),
            );
        }
        $authorization = new OperatorInventoryAuthorization(
            $container->get(AccessService::class),
            $container->get(OperatorWorkspaceStore::class),
        );
        $intelligence = new ShoppingIntelligenceService(
            $container->get(ShoppingIntelligenceStore::class),
            $authorization,
            $container->get(ConsumptionEstimator::class),
            $container->get(SuggestionEngine::class),
            $container->get(PackOptimizer::class),
            $container->get(UuidGenerator::class),
            $container->get(Clock::class),
            $container->get(TransactionManager::class),
            $container->get(ChangeFeedWriter::class),
        );
        return new OperatorShoppingService(
            new ShoppingService(
                $container->get(ShoppingStore::class),
                $authorization,
                $container->get(LegacySuggestionPolicy::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
                $container->get(ChangeFeedWriter::class),
                $intelligence,
            ),
            $container->get(ShoppingStore::class),
            $authorization,
            $container->get(AccessService::class),
            $container->get(AccessStore::class),
            $container->get(TransactionManager::class),
        );
    }
}
