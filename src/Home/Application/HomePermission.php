<?php

declare(strict_types=1);

namespace Providentia\Home\Application;

final class HomePermission
{
    public const HOME_READ = 'home.read';
    public const MEMBERS_READ = 'members.read';
    public const MEMBERS_INVITE = 'members.invite';
    public const MEMBERS_MANAGE = 'members.manage';
    public const PERMISSIONS_MANAGE = 'permissions.manage';
    public const OWNERSHIP_TRANSFER = 'ownership.transfer';
    public const INVENTORY_READ = 'inventory.read';
    public const INVENTORY_WRITE = 'inventory.write';
    public const INVENTORY_MANAGE = 'inventory.manage';
    public const PURCHASES_READ = 'purchases.read';
    public const PURCHASES_WRITE = 'purchases.write';
    public const SHOPPING_READ = 'shopping.read';
    public const SHOPPING_WRITE = 'shopping.write';
    public const SHOPPING_MANAGE = 'shopping.manage';
    public const AI_READ = 'ai.read';
    public const AI_USE = 'ai.use';
    public const AI_MANAGE = 'ai.manage';
    public const REPORTS_READ = 'reports.read';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::HOME_READ,
            self::MEMBERS_READ,
            self::MEMBERS_INVITE,
            self::MEMBERS_MANAGE,
            self::PERMISSIONS_MANAGE,
            self::OWNERSHIP_TRANSFER,
            self::INVENTORY_READ,
            self::INVENTORY_WRITE,
            self::INVENTORY_MANAGE,
            self::PURCHASES_READ,
            self::PURCHASES_WRITE,
            self::SHOPPING_READ,
            self::SHOPPING_WRITE,
            self::SHOPPING_MANAGE,
            self::AI_READ,
            self::AI_USE,
            self::AI_MANAGE,
            self::REPORTS_READ,
        ];
    }

    /** @return list<string> */
    public static function defaultsForRole(string $role): array
    {
        return match ($role) {
            HomeAuthorization::OWNER => self::all(),
            HomeAuthorization::MANAGER => [
                self::HOME_READ,
                self::MEMBERS_READ,
                self::MEMBERS_INVITE,
                self::INVENTORY_READ,
                self::INVENTORY_WRITE,
                self::INVENTORY_MANAGE,
                self::PURCHASES_READ,
                self::PURCHASES_WRITE,
                self::SHOPPING_READ,
                self::SHOPPING_WRITE,
                self::SHOPPING_MANAGE,
                self::AI_READ,
                self::AI_USE,
                self::AI_MANAGE,
                self::REPORTS_READ,
            ],
            HomeAuthorization::MEMBER => [
                self::HOME_READ,
                self::INVENTORY_READ,
                self::INVENTORY_WRITE,
                self::PURCHASES_READ,
                self::PURCHASES_WRITE,
                self::SHOPPING_READ,
                self::SHOPPING_WRITE,
                self::AI_READ,
                self::AI_USE,
                self::REPORTS_READ,
            ],
            HomeAuthorization::VIEWER => [
                self::HOME_READ,
                self::INVENTORY_READ,
                self::PURCHASES_READ,
                self::SHOPPING_READ,
                self::AI_READ,
                self::REPORTS_READ,
            ],
            default => [],
        };
    }

    public static function isKnown(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}
