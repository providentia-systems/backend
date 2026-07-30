<?php

declare(strict_types=1);

namespace Providentia\Shopping;

use Providentia\Shopping\Application\ShoppingService;
use Providentia\Shopping\Application\ShoppingIntelligenceService;
use Providentia\Shopping\Application\ShoppingIntelligenceReader;
use Providentia\Shopping\Application\ShoppingIntelligenceStore;
use Providentia\Shopping\Application\ShoppingStore;
use Providentia\Shopping\Application\ShoppingSummaryReader;
use Providentia\Shopping\Domain\ConsumptionEstimator;
use Providentia\Shopping\Domain\PackOptimizer;
use Providentia\Shopping\Domain\LegacySuggestionPolicy;
use Providentia\Shopping\Domain\SuggestionEngine;
use Providentia\Shopping\Infrastructure\Doctrine\DbalShoppingIntelligenceStore;
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
                    ShoppingIntelligenceStore::class => DbalShoppingIntelligenceStore::class,
                    ShoppingIntelligenceReader::class => DbalShoppingIntelligenceStore::class,
                ],
                'factories' => [
                    DbalShoppingStore::class => ShoppingFactory::class,
                    DbalShoppingIntelligenceStore::class => ShoppingFactory::class,
                    LegacySuggestionPolicy::class => ShoppingFactory::class,
                    ConsumptionEstimator::class => ShoppingFactory::class,
                    SuggestionEngine::class => ShoppingFactory::class,
                    PackOptimizer::class => ShoppingFactory::class,
                    ShoppingService::class => ShoppingFactory::class,
                    ShoppingIntelligenceService::class => ShoppingFactory::class,
                    'shopping.lists.list' => ShoppingFactory::class,
                    'shopping.lists.get' => ShoppingFactory::class,
                    'shopping.lists.create' => ShoppingFactory::class,
                    'shopping.lines.create' => ShoppingFactory::class,
                    'shopping.lines.check' => ShoppingFactory::class,
                    'shopping.suggestions' => ShoppingFactory::class,
                    'shopping.intelligence.estimates.list' => ShoppingFactory::class,
                    'shopping.intelligence.suggestions.list' => ShoppingFactory::class,
                    'shopping.intelligence.runs.create' => ShoppingFactory::class,
                    'shopping.intelligence.explanation.get' => ShoppingFactory::class,
                    'shopping.intelligence.prices.list' => ShoppingFactory::class,
                    'shopping.intelligence.preferences.get' => ShoppingFactory::class,
                    'shopping.intelligence.preferences.put' => ShoppingFactory::class,
                    'shopping.intelligence.feedback.create' => ShoppingFactory::class,
                    'shopping.intelligence.backtests.create' => ShoppingFactory::class,
                    'shopping.intelligence.backtests.get' => ShoppingFactory::class,
                ],
            ],
        ];
    }
}
