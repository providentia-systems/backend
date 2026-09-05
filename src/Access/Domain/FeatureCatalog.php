<?php

declare(strict_types=1);

namespace Providentia\Access\Domain;

/** The application contract for editable groups; unknown capabilities fail closed. */
final class FeatureCatalog
{
    public const ACCOUNT = 'account';
    public const HOME = 'home';
    public const ADMIN = 'admin';

    public const STARTER_ACCOUNT = 'a1000000-0000-4000-8000-000000000001';
    public const INVITED_ACCOUNT = 'a1000000-0000-4000-8000-000000000002';
    public const STARTER_HOME = 'a1000000-0000-4000-8000-000000000003';
    public const SYSTEM_OWNER = 'a1000000-0000-4000-8000-000000000004';

    /** @return list<string> */
    public static function features(string $scope): array
    {
        return match ($scope) {
            self::ACCOUNT => ['homes.create', 'homes.join'],
            self::HOME => [
                'home.read', 'home.manage', 'members.read', 'members.invite', 'members.manage',
                'permissions.manage', 'ownership.transfer', 'inventory.read', 'inventory.write',
                'inventory.manage', 'purchases.read', 'purchases.write', 'shopping.read',
                'shopping.write', 'shopping.manage', 'ai.read', 'ai.use', 'ai.manage',
                'ai.credentials.use', 'ai.platform.use', 'reports.read', 'catalog.contribute',
                'catalog.import', 'catalog.consent.manage', 'data.export', 'data.erasure',
                'billing.read', 'billing.manage',
            ],
            self::ADMIN => [
                'accounts.read', 'accounts.manage', 'accounts.assign', 'people.read',
                'homes.read', 'homes.manage', 'homes.assign', 'groups.manage',
                'administrators.read', 'administrators.approve', 'administrators.manage',
                'catalog.read', 'catalog.review', 'catalog.curate', 'countries.manage',
                'policies.manage', 'audit.read', 'billing.read', 'billing.manage',
            ],
            default => [],
        };
    }

    /** @return list<string> */
    public static function limits(string $scope): array
    {
        return match ($scope) {
            self::ACCOUNT => ['homes.owned', 'homes.joined'],
            self::HOME => [
                'members.total', 'members.owners', 'members.managers', 'members.members',
                'categories.total', 'products.total', 'locations.total',
            ],
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        $home = array_fill_keys(self::features(self::HOME), true);
        $home['members.invite'] = false;
        $home['ai.platform.use'] = false;
        $home['billing.manage'] = false;
        return [
            self::group(self::STARTER_ACCOUNT, self::ACCOUNT, 'Starter account',
                ['homes.create' => true, 'homes.join' => true], ['homes.owned' => 1, 'homes.joined' => -1]),
            self::group(self::INVITED_ACCOUNT, self::ACCOUNT, 'Invited account',
                ['homes.create' => false, 'homes.join' => true], ['homes.owned' => 0, 'homes.joined' => -1]),
            self::group(self::STARTER_HOME, self::HOME, 'Starter home', $home, [
                'members.total' => 10, 'members.owners' => 1, 'members.managers' => 3,
                'members.members' => 9, 'categories.total' => -1, 'products.total' => -1,
                'locations.total' => -1,
            ]),
            self::group(self::SYSTEM_OWNER, self::ADMIN, 'System owner',
                array_fill_keys(self::features(self::ADMIN), true), [], true),
        ];
    }

    /**
     * @param array<string, bool> $features
     * @param array<string, int> $limits
     * @return array<string, mixed>
     */
    private static function group(
        string $id,
        string $scope,
        string $name,
        array $features,
        array $limits,
        bool $protected = false,
    ): array {
        return [
            'id' => $id, 'scope' => $scope, 'name' => $name, 'description' => '',
            'features' => $features, 'limits' => $limits,
            'delegablePermissions' => $scope === self::HOME ? self::features(self::HOME) : [],
            'protected' => $protected, 'revision' => 1,
            'rolePermissions' => $scope === self::HOME ? [
                'manager' => ['home.read', 'home.manage', 'members.read', 'members.invite', 'inventory.read', 'inventory.write', 'inventory.manage', 'purchases.read', 'purchases.write', 'shopping.read', 'shopping.write', 'shopping.manage', 'ai.read', 'ai.use', 'ai.manage', 'ai.credentials.use', 'reports.read', 'catalog.contribute', 'catalog.import'],
                'member' => ['home.read', 'inventory.read', 'inventory.write', 'purchases.read', 'purchases.write', 'shopping.read', 'shopping.write', 'ai.read', 'ai.use', 'reports.read', 'catalog.contribute', 'catalog.import'],
                'viewer' => ['home.read', 'inventory.read', 'purchases.read', 'shopping.read', 'ai.read', 'reports.read'],
            ] : [],
        ];
    }
}
