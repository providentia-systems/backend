<?php

declare(strict_types=1);

namespace Providentia\Reporting\Application;

use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Inventory\Application\InventorySummaryReader;
use Providentia\Purchasing\Application\PurchaseSummaryReader;
use Providentia\Shopping\Application\ShoppingSummaryReader;

final class DashboardService
{
    public function __construct(
        private readonly HomeAuthorization $authorization,
        private readonly InventorySummaryReader $inventory,
        private readonly PurchaseSummaryReader $purchases,
        private readonly ShoppingSummaryReader $shopping,
    ) {
    }

    /** @return array<string, mixed> */
    public function dashboard(AuthenticatedIdentity $identity, string $homeId): array
    {
        $membership = $this->authorization->requirePermission(
            $identity,
            $homeId,
            HomePermission::REPORTS_READ,
        );

        return [
            'homeId' => $homeId,
            'viewer' => [
                'userId' => $identity->userId,
                'role' => $membership['role'],
            ],
            'inventory' => $this->inventory->inventorySummary($homeId),
            'purchases' => $this->purchases->summary($homeId, 90),
            'shopping' => $this->shopping->shoppingSummary($homeId),
            'definitions' => [
                'itemMasterCount' => 'Published non-archived catalog packs.',
                'countedProductCount' => 'Active products explicitly added to this home.',
                'belowConfiguredMinimumCount' => 'Only products with an explicit home minimum.',
                'recentPurchases' => 'Committed receipts within the previous 90 days.',
            ],
        ];
    }
}
