<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\Home\Application\HomePermission;

/** An explicit allowlist, shared by snapshot, change-feed and receipt reads. */
final class SyncReadPolicy
{
    /** @var array<string, string> */
    public const ENTITY_PERMISSIONS = [
        'private-note' => HomePermission::HOME_READ,
        'home-preference' => HomePermission::HOME_READ,
        'inventory-location' => HomePermission::INVENTORY_READ,
        'inventory-home-category' => HomePermission::INVENTORY_READ,
        'inventory-home-product' => HomePermission::INVENTORY_READ,
        'inventory-balance' => HomePermission::INVENTORY_READ,
        'inventory-count-session' => HomePermission::INVENTORY_READ,
        'inventory-count-line' => HomePermission::INVENTORY_READ,
        'purchasing-store' => HomePermission::PURCHASES_READ,
        'purchasing-receipt' => HomePermission::PURCHASES_READ,
        'purchasing-receipt-line' => HomePermission::PURCHASES_READ,
        'shopping-stock-preference' => HomePermission::SHOPPING_READ,
        'shopping-list' => HomePermission::SHOPPING_READ,
        'shopping-list-line' => HomePermission::SHOPPING_READ,
        'shopping-suggestion-feedback' => HomePermission::SHOPPING_READ,
    ];

    /** @var array<string, string> */
    private const COMMAND_ENTITIES = [
        'inventory.location.create' => 'inventory-location',
        'inventory.location.update' => 'inventory-location',
        'inventory.home-category.create' => 'inventory-home-category',
        'inventory.home-category.update' => 'inventory-home-category',
        'inventory.home-product.create' => 'inventory-home-product',
        'inventory.home-product.update' => 'inventory-home-product',
        'inventory.adjustment.create' => 'inventory-balance',
        'inventory.count-session.create' => 'inventory-count-session',
        'inventory.count-session.close' => 'inventory-count-session',
        'inventory.count-session.cancel' => 'inventory-count-session',
        'inventory.count-line.upsert' => 'inventory-count-line',
        'inventory.count-line.remove' => 'inventory-count-line',
        'purchasing.store.create' => 'purchasing-store',
        'purchasing.store.update' => 'purchasing-store',
        'purchasing.receipt.create' => 'purchasing-receipt',
        'purchasing.receipt.update' => 'purchasing-receipt',
        'purchasing.receipt.cancel' => 'purchasing-receipt',
        'purchasing.receipt.commit' => 'purchasing-receipt',
        'purchasing.receipt-line.create' => 'purchasing-receipt-line',
        'purchasing.receipt-line.update' => 'purchasing-receipt-line',
        'purchasing.receipt-line.remove' => 'purchasing-receipt-line',
        'purchasing.receipt-line.approve' => 'purchasing-receipt-line',
        'purchasing.receipt-line.unresolve' => 'purchasing-receipt-line',
        'shopping.preference.put' => 'shopping-stock-preference',
        'shopping.list.create' => 'shopping-list',
        'shopping.list.update' => 'shopping-list',
        'shopping.list-line.create' => 'shopping-list-line',
        'shopping.list-line.update' => 'shopping-list-line',
        'shopping.list-line.checked' => 'shopping-list-line',
        'shopping.suggestion-feedback.create' => 'shopping-suggestion-feedback',
    ];

    public static function entityForCommand(string $commandType): ?string
    {
        // Do not authorize an unknown command by its prefix.
        return self::COMMAND_ENTITIES[$commandType] ?? null;
    }

    /** @param list<string> $permissions */
    public static function canRead(?string $entityType, array $permissions): bool
    {
        $permission = self::ENTITY_PERMISSIONS[$entityType ?? ''] ?? null;

        return $permission !== null && in_array($permission, $permissions, true);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $permissions
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, array $permissions): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => self::canRead(
                is_string($row['entityType'] ?? null) ? $row['entityType'] : null,
                $permissions,
            ),
        ));
    }

    /**
     * Keep an accepted operation accepted without returning its denied data.
     * This is a response projection, never a change to the immutable receipt.
     *
     * @param array<string, mixed> $result
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public static function result(array $result, array $permissions, ?string $entityType = null): array
    {
        $entityType ??= is_string($result['commandType'] ?? null)
            ? self::entityForCommand($result['commandType'])
            : (is_string($result['entityType'] ?? null) ? $result['entityType'] : null);
        if (self::canRead($entityType, $permissions)) {
            return $result;
        }

        // Even detail/conflict text can contain private values. Only receipt
        // identity and outcome are safe without a classified read permission.
        return array_intersect_key($result, ['operationId' => true, 'status' => true]);
    }
}
