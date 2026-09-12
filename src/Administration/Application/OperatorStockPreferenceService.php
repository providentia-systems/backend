<?php

declare(strict_types=1);

namespace Providentia\Administration\Application;

use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Catalog\Application\CatalogQueryService;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Shopping\Application\ShoppingIntelligenceService;
use Providentia\Shopping\Application\ShoppingIntelligenceStore;

final class OperatorStockPreferenceService
{
    public function __construct(
        private readonly ShoppingIntelligenceService $intelligence,
        private readonly ShoppingIntelligenceStore $preferences,
        private readonly InventoryStore $inventory,
        private readonly CatalogQueryService $catalog,
        private readonly OperatorInventoryAuthorization $authorization,
        private readonly AccessService $access,
        private readonly AccessStore $audit,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(AuthenticatedIdentity $actor, string $homeId, string $productId): array
    {
        $this->authorization->requirePermission($actor, $homeId, HomePermission::SHOPPING_READ);
        $product = $this->requireProduct($homeId, $productId);
        $result = $this->intelligence->preference($actor, $homeId, $productId);
        $canonical = $product['productId'] === null ? null : $this->catalog->product((string) $product['productId']);
        $result['packOptions'] = [];
        if ($canonical !== null && $canonical['id'] === $product['productId']) {
            /** @var list<array<string, mixed>> $packs */
            $packs = $canonical['packs'];
            foreach ($packs as $pack) {
                $result['packOptions'][] = ['id' => (string) $pack['id'], 'label' => (string) $pack['packText']];
            }
        }
        $this->audit->audit($actor->userId, 'operator.home.stock-preference.viewed', 'home', $homeId, [
            'homeProductId' => $productId,
        ]);
        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{revision: int}
     */
    public function put(AuthenticatedIdentity $actor, string $homeId, string $productId, array $input): array
    {
        $this->authorization->requirePermission($actor, $homeId, HomePermission::SHOPPING_MANAGE);
        $reason = $input['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason)) > 500) {
            throw new Problem(422, 'Audit reason required', 'Provide a reason containing 1 to 500 characters.');
        }
        unset($input['reason']);
        return $this->transactions->transactional(function () use (
            $actor,
            $homeId,
            $productId,
            $input,
            $reason,
        ): array {
            $this->access->serialize('home', $homeId);
            $this->requireProduct($homeId, $productId);
            $before = $this->preferences->preference($homeId, $productId);
            $result = $this->intelligence->putPreference($actor, $homeId, $productId, $input);
            $this->audit->audit($actor->userId, 'operator.home.stock-preference.saved', 'home', $homeId, [
                'homeProductId' => $productId,
                'reason' => trim($reason),
                'expectedRevision' => $input['expectedRevision'],
                'before' => $before,
                'after' => $this->preferences->preference($homeId, $productId),
            ]);
            return $result;
        });
    }

    /** @return array<string, mixed> */
    private function requireProduct(string $homeId, string $productId): array
    {
        return $this->inventory->homeProduct($homeId, $productId, true)
            ?? throw new Problem(404, 'Product unavailable', 'The household product does not exist.');
    }
}
