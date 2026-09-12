<?php

declare(strict_types=1);

namespace Providentia\Administration\Infrastructure\Factory;

use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Administration\Application\OperatorInventoryAuthorization;
use Providentia\Administration\Application\OperatorStockPreferenceService;
use Providentia\Administration\Application\OperatorWorkspaceStore;
use Providentia\Administration\Http\OperatorStockPreferenceHandler;
use Providentia\Catalog\Application\CatalogQueryService;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\Shopping\Application\ShoppingIntelligenceService;
use Providentia\Shopping\Application\ShoppingIntelligenceStore;
use Providentia\Shopping\Domain\ConsumptionEstimator;
use Providentia\Shopping\Domain\PackOptimizer;
use Providentia\Shopping\Domain\SuggestionEngine;
use Psr\Container\ContainerInterface;

final class OperatorStockPreferenceFactory
{
    public function __invoke(ContainerInterface $container, string $requestedName): object
    {
        if ($requestedName === OperatorStockPreferenceHandler::class) {
            return new OperatorStockPreferenceHandler($container->get(OperatorStockPreferenceService::class));
        }
        $authorization = new OperatorInventoryAuthorization(
            $container->get(AccessService::class),
            $container->get(OperatorWorkspaceStore::class),
        );
        return new OperatorStockPreferenceService(
            new ShoppingIntelligenceService(
                $container->get(ShoppingIntelligenceStore::class),
                $authorization,
                $container->get(ConsumptionEstimator::class),
                $container->get(SuggestionEngine::class),
                $container->get(PackOptimizer::class),
                $container->get(UuidGenerator::class),
                $container->get(Clock::class),
                $container->get(TransactionManager::class),
                $container->get(ChangeFeedWriter::class),
            ),
            $container->get(ShoppingIntelligenceStore::class),
            $container->get(InventoryStore::class),
            $container->get(CatalogQueryService::class),
            $authorization,
            $container->get(AccessService::class),
            $container->get(AccessStore::class),
            $container->get(TransactionManager::class),
        );
    }
}
