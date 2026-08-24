<?php

declare(strict_types=1);

namespace Providentia\Inventory\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Providentia\Inventory\Application\InventoryAnalyticsReader;
use Providentia\Inventory\Application\InventoryStore;
use Providentia\Inventory\Application\InventorySummaryReader;

final class DbalInventoryStore implements InventoryStore, InventorySummaryReader, InventoryAnalyticsReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function categories(string $homeId, bool $includeArchived): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, status, revision, created_at AS createdAt,
                    updated_at AS updatedAt, archived_at AS archivedAt
             FROM home_categories
             WHERE home_id = :home AND (:include_archived = 1 OR status = :active)
             ORDER BY normalized_name, id',
            [
                'home' => $homeId,
                'include_archived' => $includeArchived ? 1 : 0,
                'active' => 'active',
            ],
        );

        return array_map(fn (array $row): array => $this->categoryRecord($row), $rows);
    }

    public function createHomeCategory(
        string $id,
        string $homeId,
        string $name,
        string $normalizedName,
        DateTimeImmutable $at,
    ): void {
        if (
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM home_categories
                 WHERE home_id = :home AND normalized_name = :name',
                ['home' => $homeId, 'name' => $normalizedName],
            ) > 0
        ) {
            throw new \DomainException('A category with this name already exists in the home.');
        }
        $now = $this->date($at);
        try {
            $this->connection->insert('home_categories', [
                'id' => $id,
                'home_id' => $homeId,
                'name' => $name,
                'normalized_name' => $normalizedName,
                'status' => 'active',
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'archived_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('A category with this name already exists in the home.');
        }
    }

    public function updateHomeCategory(
        string $homeId,
        string $categoryId,
        ?string $name,
        ?string $normalizedName,
        ?string $status,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): array {
        $row = $this->one(
            $this->forUpdate('SELECT id, name, normalized_name, status, revision,
                    created_at AS createdAt, updated_at AS updatedAt, archived_at AS archivedAt
             FROM home_categories
             WHERE home_id = :home AND id = :id'),
            ['home' => $homeId, 'id' => $categoryId],
        );
        if ($row === null) {
            return ['status' => 'not-found'];
        }
        if ((int) $row['revision'] !== $expectedRevision) {
            return ['status' => 'revision-conflict'];
        }
        $nextName = $name ?? (string) $row['name'];
        $nextNormalizedName = $normalizedName ?? (string) $row['normalized_name'];
        $nextStatus = $status ?? (string) $row['status'];
        if (
            $nextNormalizedName !== (string) $row['normalized_name']
            && (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM home_categories
                 WHERE home_id = :home AND normalized_name = :name AND id <> :id',
                ['home' => $homeId, 'name' => $nextNormalizedName, 'id' => $categoryId],
            ) > 0
        ) {
            throw new \DomainException('A category with this name already exists in the home.');
        }
        if ($nextStatus === 'archived' && (string) $row['status'] !== 'archived') {
            $inUse = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM home_products
                 WHERE home_id = :home AND home_category_id = :category AND status = :active',
                ['home' => $homeId, 'category' => $categoryId, 'active' => 'active'],
            );
            if ($inUse > 0) {
                return ['status' => 'category-in-use'];
            }
        }
        $now = $this->date($at);
        try {
            $updated = $this->connection->update('home_categories', [
                'name' => $nextName,
                'normalized_name' => $nextNormalizedName,
                'status' => $nextStatus,
                'revision' => $expectedRevision + 1,
                'updated_at' => $now,
                'archived_at' => $nextStatus === 'archived' ? $now : null,
            ], ['home_id' => $homeId, 'id' => $categoryId, 'revision' => $expectedRevision]);
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('A category with this name already exists in the home.');
        }
        if ($updated !== 1) {
            return ['status' => 'revision-conflict'];
        }

        return [
            'status' => 'updated',
            'record' => $this->categoryRecord([
                'id' => $categoryId,
                'name' => $nextName,
                'status' => $nextStatus,
                'revision' => $expectedRevision + 1,
                'createdAt' => $row['createdAt'],
                'updatedAt' => $now,
                'archivedAt' => $nextStatus === 'archived' ? $now : null,
            ]),
        ];
    }

    public function locations(string $homeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, name, kind, status, revision, created_at AS createdAt,
                    updated_at AS updatedAt
             FROM home_locations
             WHERE home_id = :home AND status = :status
             ORDER BY name, id',
            ['home' => $homeId, 'status' => 'active'],
        );
    }

    public function createLocation(
        string $id,
        string $homeId,
        string $name,
        string $normalizedName,
        string $kind,
        DateTimeImmutable $at,
    ): void {
        $now = $this->date($at);
        $this->connection->insert('home_locations', [
            'id' => $id,
            'home_id' => $homeId,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'kind' => $kind,
            'status' => 'active',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function itemMaster(
        string $homeId,
        string $query,
        ?string $categoryId,
        ?string $homeCategoryId,
        int $limit,
        int $offset,
    ): array {
        $pattern = '%' . mb_strtolower($query) . '%';
        $globalWhere = 'p.status = :published AND pk.status <> :archived
            AND :home_category_empty = :empty
            AND (:category_empty = :empty OR c.id = :category)
            AND (:query_empty = :empty OR p.normalized_name LIKE :pattern
                 OR p.normalized_brand LIKE :pattern
                 OR pk.original_pack_text LIKE :pattern
                 OR EXISTS (
                     SELECT 1 FROM product_aliases a
                     WHERE a.product_id = p.id AND a.status = :approved
                       AND (a.scope = :global_scope
                            OR (a.scope = :home_scope AND a.home_id = :home))
                       AND (a.pack_id IS NULL OR a.pack_id = pk.id)
                       AND (a.variant_id IS NULL OR a.variant_id = pk.variant_id)
                       AND a.normalized_alias LIKE :pattern
                 ))';
        $privateWhere = 'hp.home_id = :home AND hp.status = :home_product_status
            AND hp.product_id IS NULL AND hp.pack_id IS NULL
            AND :category_empty = :empty
            AND (:home_category_empty = :empty OR hc.id = :home_category)
            AND (:query_empty = :empty OR hp.normalized_private_name LIKE :pattern
                 OR hp.original_pack_text LIKE :pattern)';
        $parameters = [
            'home' => $homeId,
            'published' => 'published',
            'archived' => 'archived',
            'home_product_status' => 'active',
            'category_empty' => $categoryId ?? '',
            'category' => $categoryId ?? '',
            'home_category_empty' => $homeCategoryId ?? '',
            'home_category' => $homeCategoryId ?? '',
            'query_empty' => $query,
            'empty' => '',
            'pattern' => $pattern,
            'approved' => 'approved',
            'global_scope' => 'global',
            'home_scope' => 'home',
        ];
        $union = 'SELECT pk.id AS packId, pk.variant_id AS variantId, p.id AS productId,
                         p.canonical_name AS canonicalName, p.brand,
                         c.id AS categoryId, NULL AS homeCategoryId,
                         c.canonical_name AS categoryName, :global_scope AS categorySource,
                         pk.original_pack_text AS packText, pk.status AS packStatus,
                         hp.id AS homeProductId, hp.status AS homeProductStatus,
                         COALESCE(ib.quantity, 0) AS quantity,
                         p.normalized_name AS sortName, p.normalized_brand AS sortBrand
                  FROM product_packs pk
                  INNER JOIN products p ON p.id = pk.product_id
                  INNER JOIN categories c ON c.id = p.category_id
                  LEFT JOIN home_products hp
                    ON hp.id = (
                        SELECT MIN(hp2.id) FROM home_products hp2
                        WHERE hp2.home_id = :home AND hp2.pack_id = pk.id
                          AND hp2.status = :home_product_status
                    )
                  LEFT JOIN inventory_balances ib
                    ON ib.home_id = :home AND ib.home_product_id = hp.id
                  WHERE ' . $globalWhere . '
                  UNION ALL
                  SELECT NULL AS packId, NULL AS variantId, NULL AS productId,
                         hp.private_name AS canonicalName, :empty AS brand,
                         NULL AS categoryId, hc.id AS homeCategoryId,
                         hc.name AS categoryName,
                         CASE WHEN hc.id IS NULL THEN NULL ELSE :home_scope END AS categorySource,
                         COALESCE(hp.original_pack_text, :empty) AS packText,
                         NULL AS packStatus, hp.id AS homeProductId,
                         hp.status AS homeProductStatus, COALESCE(ib.quantity, 0) AS quantity,
                         hp.normalized_private_name AS sortName, :empty AS sortBrand
                  FROM home_products hp
                  LEFT JOIN home_categories hc
                    ON hc.id = hp.home_category_id AND hc.home_id = hp.home_id
                  LEFT JOIN inventory_balances ib
                    ON ib.home_id = hp.home_id AND ib.home_product_id = hp.id
                  WHERE ' . $privateWhere;
        $items = $this->connection->fetchAllAssociative(
            'SELECT * FROM (' . $union . ') item_master
             ORDER BY sortName, sortBrand, packText, productId, packId, homeProductId
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $parameters,
        );
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (' . $union . ') item_master_count',
            $parameters,
        );
        if ($items === []) {
            return ['items' => [], 'total' => $total];
        }

        $aliases = $this->aliasesForItemMasterPage($homeId, $items);
        foreach ($items as &$item) {
            $packId = $item['packId'] === null ? null : (string) $item['packId'];
            $item['aliases'] = $packId === null ? [] : ($aliases[$packId] ?? []);
            $item['homeProductId'] = $item['homeProductId'] === null ? null : (string) $item['homeProductId'];
            $item['homeProductStatus'] = $item['homeProductStatus'] === null
                ? null
                : (string) $item['homeProductStatus'];
            $item['quantity'] = (string) $item['quantity'];
            unset($item['variantId'], $item['sortName'], $item['sortBrand']);
        }
        unset($item);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, list<string>>
     */
    private function aliasesForItemMasterPage(string $homeId, array $items): array
    {
        $globalItems = array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['packId'] !== null && $item['productId'] !== null,
        ));
        if ($globalItems === []) {
            return [];
        }
        $packIds = array_values(array_unique(array_map(
            static fn (array $item): string => (string) $item['packId'],
            $globalItems,
        )));
        $productIds = array_values(array_unique(array_map(
            static fn (array $item): string => (string) $item['productId'],
            $globalItems,
        )));
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'product_id', 'variant_id', 'pack_id', 'raw_alias')
            ->from('product_aliases')
            ->where('status = :approved')
            ->andWhere('(scope = :global OR (scope = :home_scope AND home_id = :home))')
            ->andWhere('(pack_id IN (:packs) OR (pack_id IS NULL AND product_id IN (:products)))')
            ->orderBy('normalized_alias', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setParameter('approved', 'approved')
            ->setParameter('global', 'global')
            ->setParameter('home_scope', 'home')
            ->setParameter('home', $homeId)
            ->setParameter('packs', $packIds, ArrayParameterType::STRING)
            ->setParameter('products', $productIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();
        $aliases = array_fill_keys($packIds, []);
        foreach ($rows as $row) {
            foreach ($globalItems as $item) {
                if ((string) $item['productId'] !== (string) $row['product_id']) {
                    continue;
                }
                if ($row['pack_id'] !== null && (string) $item['packId'] !== (string) $row['pack_id']) {
                    continue;
                }
                if ($row['variant_id'] !== null && (string) $item['variantId'] !== (string) $row['variant_id']) {
                    continue;
                }
                $packId = (string) $item['packId'];
                $alias = (string) $row['raw_alias'];
                if (! in_array($alias, $aliases[$packId], true)) {
                    $aliases[$packId][] = $alias;
                }
            }
        }

        return $aliases;
    }

    public function stock(
        string $homeId,
        string $query,
        ?string $categoryId,
        ?string $homeCategoryId,
        int $limit,
        int $offset,
    ): array {
        $pattern = '%' . mb_strtolower($query) . '%';

        $rows = $this->connection->fetchAllAssociative(
            'SELECT hp.id AS homeProductId, hp.product_id AS productId, hp.pack_id AS packId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    COALESCE(p.brand, :empty) AS brand,
                    c.id AS categoryId, hc.id AS homeCategoryId,
                    COALESCE(c.canonical_name, hc.name) AS category,
                    COALESCE(c.canonical_name, hc.name) AS categoryName,
                    CASE WHEN c.id IS NOT NULL THEN :global_scope
                         WHEN hc.id IS NOT NULL THEN :home_scope ELSE NULL END AS categorySource,
                    COALESCE(pk.original_pack_text, hp.original_pack_text, :empty) AS packText,
                    COALESCE(ib.quantity, 0) AS quantity,
                    COALESCE(ib.revision, 0) AS balanceRevision,
                    sp.minimum_quantity AS minimumQuantity,
                    sp.always_keep AS alwaysKeep, sp.never_suggest AS neverSuggest,
                    hp.revision, hp.updated_at AS updatedAt
             FROM home_products hp
             LEFT JOIN products p ON p.id = hp.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN home_categories hc
               ON hc.id = hp.home_category_id AND hc.home_id = hp.home_id
             LEFT JOIN product_packs pk ON pk.id = hp.pack_id
             LEFT JOIN inventory_balances ib
               ON ib.home_id = hp.home_id AND ib.home_product_id = hp.id
             LEFT JOIN stock_threshold_preferences sp
               ON sp.home_id = hp.home_id AND sp.home_product_id = hp.id
             WHERE hp.home_id = :home AND hp.status = :status
               AND (:category_empty = :empty OR c.id = :category)
               AND (:home_category_empty = :empty OR hc.id = :home_category)
               AND (:query_empty = :empty
                    OR p.normalized_name LIKE :pattern
                    OR p.normalized_brand LIKE :pattern
                    OR hp.normalized_private_name LIKE :pattern
                    OR pk.original_pack_text LIKE :pattern
                    OR EXISTS (
                        SELECT 1 FROM product_aliases a
                        WHERE a.product_id = hp.product_id
                          AND a.status = :approved
                          AND (a.scope = :global_scope OR a.home_id = :home)
                          AND a.normalized_alias LIKE :pattern
                    ))
             ORDER BY productName, brand, packText, hp.id
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            [
                'home' => $homeId,
                'status' => 'active',
                'category_empty' => $categoryId ?? '',
                'category' => $categoryId ?? '',
                'home_category_empty' => $homeCategoryId ?? '',
                'home_category' => $homeCategoryId ?? '',
                'query_empty' => $query,
                'empty' => '',
                'pattern' => $pattern,
                'approved' => 'approved',
                'global_scope' => 'global',
                'home_scope' => 'home',
            ],
        );

        return array_map(function (array $row): array {
            $row['balanceRevision'] = (int) $row['balanceRevision'];
            $row['revision'] = (int) $row['revision'];
            $row['quantity'] = (string) $row['quantity'];
            $row['minimumQuantity'] = $row['minimumQuantity'] === null
                ? null
                : (string) $row['minimumQuantity'];
            $row['alwaysKeep'] = (bool) $row['alwaysKeep'];
            $row['neverSuggest'] = (bool) $row['neverSuggest'];
            $row['updatedAt'] = $this->atom((string) $row['updatedAt']);

            return $row;
        }, $rows);
    }

    public function inventoryReport(string $homeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT hp.id AS homeProductId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    COALESCE(pk.original_pack_text, hp.original_pack_text, :empty) AS packText,
                    COALESCE(ib.quantity, 0) AS factualQuantity,
                    ib.revision AS balanceRevision,
                    ib.last_movement_id AS lastMovementId,
                    ib.updated_at AS balanceUpdatedAt,
                    sp.minimum_quantity AS configuredMinimum,
                    sp.always_keep AS alwaysKeep,
                    sp.never_suggest AS neverSuggest
             FROM home_products hp
             LEFT JOIN products p ON p.id = hp.product_id
             LEFT JOIN product_packs pk ON pk.id = hp.pack_id
             LEFT JOIN inventory_balances ib
               ON ib.home_id = hp.home_id AND ib.home_product_id = hp.id
             LEFT JOIN stock_threshold_preferences sp
               ON sp.home_id = hp.home_id AND sp.home_product_id = hp.id
             WHERE hp.home_id = :home AND hp.status = :status
             ORDER BY productName, hp.id',
            ['empty' => '', 'home' => $homeId, 'status' => 'active'],
        );
    }

    public function homeProduct(string $homeId, string $homeProductId): ?array
    {
        return $this->one(
            'SELECT hp.id, hp.home_id AS homeId, hp.product_id AS productId,
                    hp.pack_id AS packId, hp.private_name AS privateName,
                    hp.original_pack_text AS originalPackText,
                    hp.home_category_id AS homeCategoryId, hp.status, hp.revision,
                    COALESCE(p.canonical_name, hp.private_name) AS productName
             FROM home_products hp
             LEFT JOIN products p ON p.id = hp.product_id
             WHERE hp.home_id = :home AND hp.id = :id AND hp.status = :status',
            ['home' => $homeId, 'id' => $homeProductId, 'status' => 'active'],
        );
    }

    public function createHomeProduct(
        string $id,
        string $homeId,
        ?string $productId,
        ?string $packId,
        ?string $privateName,
        ?string $normalizedPrivateName,
        ?string $originalPackText,
        ?string $homeCategoryId,
        DateTimeImmutable $at,
    ): void {
        if ($productId !== null) {
            $catalogProduct = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM products
                 WHERE id = :product AND status = :status',
                ['product' => $productId, 'status' => 'published'],
            );
            if ((int) $catalogProduct !== 1) {
                throw new \DomainException('The selected catalog product is unavailable.');
            }
        }
        if ($packId !== null) {
            $catalogPack = $this->connection->fetchAssociative(
                'SELECT product_id FROM product_packs
                 WHERE id = :pack AND status <> :status',
                ['pack' => $packId, 'status' => 'archived'],
            );
            if (
                $catalogPack === false
                || ($productId !== null && (string) $catalogPack['product_id'] !== $productId)
            ) {
                throw new \DomainException('The selected catalog pack is unavailable.');
            }
            $productId ??= (string) $catalogPack['product_id'];
        }
        if ($homeCategoryId !== null) {
            if ($productId !== null || $packId !== null) {
                throw new \DomainException('A private category can only be assigned to a private product.');
            }
            $category = $this->one(
                $this->forUpdate('SELECT id FROM home_categories
                 WHERE id = :category AND home_id = :home AND status = :active'),
                ['category' => $homeCategoryId, 'home' => $homeId, 'active' => 'active'],
            );
            if ($category === null) {
                throw new \DomainException('The selected private category is unavailable.');
            }
        }
        $now = $this->date($at);
        $this->connection->insert('home_products', [
            'id' => $id,
            'home_id' => $homeId,
            'product_id' => $productId,
            'pack_id' => $packId,
            'private_name' => $privateName,
            'normalized_private_name' => $normalizedPrivateName,
            'original_pack_text' => $originalPackText,
            'home_category_id' => $homeCategoryId,
            'status' => 'active',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updateHomeProduct(
        string $homeId,
        string $homeProductId,
        bool $privateNameProvided,
        ?string $privateName,
        ?string $normalizedPrivateName,
        bool $originalPackTextProvided,
        ?string $originalPackText,
        bool $homeCategoryProvided,
        ?string $homeCategoryId,
        ?string $status,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): array {
        $productSql = 'SELECT id, product_id AS productId, pack_id AS packId,
                              private_name AS privateName,
                              normalized_private_name AS normalizedPrivateName,
                              original_pack_text AS originalPackText,
                              home_category_id AS homeCategoryId, status, revision
                       FROM home_products
                       WHERE home_id = :home AND id = :id';
        $parameters = ['home' => $homeId, 'id' => $homeProductId];
        $row = $this->one(
            $productSql,
            $parameters,
        );
        if ($row === null) {
            return ['status' => 'not-found'];
        }
        if ((int) $row['revision'] !== $expectedRevision) {
            return ['status' => 'revision-conflict'];
        }
        if ($row['productId'] !== null || $row['packId'] !== null) {
            return ['status' => 'catalog-product'];
        }
        $nextCategoryId = $homeCategoryProvided ? $homeCategoryId : $row['homeCategoryId'];
        if ($nextCategoryId !== null) {
            $category = $this->one(
                $this->forUpdate('SELECT id FROM home_categories
                 WHERE id = :category AND home_id = :home AND status = :active'),
                ['category' => $nextCategoryId, 'home' => $homeId, 'active' => 'active'],
            );
            if ($category === null) {
                return ['status' => 'category-unavailable'];
            }
        }
        // Category changes take the category lock before the product lock, the
        // same order used while archiving a category. Re-read the product under
        // lock so a concurrent edit is still rejected by its revision CAS.
        $row = $this->one($this->forUpdate($productSql), $parameters);
        if ($row === null) {
            return ['status' => 'not-found'];
        }
        if ((int) $row['revision'] !== $expectedRevision) {
            return ['status' => 'revision-conflict'];
        }
        if ($row['productId'] !== null || $row['packId'] !== null) {
            return ['status' => 'catalog-product'];
        }
        $nextCategoryId = $homeCategoryProvided ? $homeCategoryId : $row['homeCategoryId'];
        $nextStatus = $status ?? (string) $row['status'];
        if ($nextStatus === 'archived' && (string) $row['status'] !== 'archived') {
            $balance = $this->connection->fetchOne(
                'SELECT COALESCE(quantity, 0) FROM inventory_balances
                 WHERE home_id = :home AND home_product_id = :product',
                ['home' => $homeId, 'product' => $homeProductId],
            );
            if ($balance !== false && preg_match('/^[+-]?0+(?:\.0+)?$/', trim((string) $balance)) !== 1) {
                return ['status' => 'balance-not-zero'];
            }
            $activeUse = (int) $this->connection->fetchOne(
                'SELECT
                    (SELECT COUNT(*) FROM stock_count_lines cl
                     INNER JOIN stock_count_sessions cs
                       ON cs.id = cl.session_id AND cs.home_id = cl.home_id
                     WHERE cl.home_id = :home AND cl.home_product_id = :product
                       AND cs.status = :open)
                  + (SELECT COUNT(*) FROM receipt_lines rl
                     INNER JOIN receipts r
                       ON r.id = rl.receipt_id AND r.home_id = rl.home_id
                     WHERE rl.home_id = :home AND rl.home_product_id = :product
                       AND r.status NOT IN (:committed, :cancelled))',
                [
                    'home' => $homeId,
                    'product' => $homeProductId,
                    'open' => 'open',
                    'committed' => 'committed',
                    'cancelled' => 'cancelled',
                ],
            );
            if ($activeUse > 0) {
                return ['status' => 'product-in-use'];
            }
        }
        $now = $this->date($at);
        $nextPrivateName = $privateNameProvided ? $privateName : $row['privateName'];
        $nextNormalizedPrivateName = $privateNameProvided
            ? $normalizedPrivateName
            : $row['normalizedPrivateName'];
        $nextPackText = $originalPackTextProvided ? $originalPackText : $row['originalPackText'];
        $updated = $this->connection->update('home_products', [
            'private_name' => $nextPrivateName,
            'normalized_private_name' => $nextNormalizedPrivateName,
            'original_pack_text' => $nextPackText,
            'home_category_id' => $nextCategoryId,
            'status' => $nextStatus,
            'revision' => $expectedRevision + 1,
            'updated_at' => $now,
        ], ['home_id' => $homeId, 'id' => $homeProductId, 'revision' => $expectedRevision]);
        if ($updated !== 1) {
            return ['status' => 'revision-conflict'];
        }

        return [
            'status' => 'updated',
            'record' => [
                'id' => $homeProductId,
                'productId' => null,
                'packId' => null,
                'privateName' => $nextPrivateName,
                'originalPackText' => $nextPackText,
                'homeCategoryId' => $nextCategoryId,
                'status' => $nextStatus,
                'revision' => $expectedRevision + 1,
                'updatedAt' => $this->atom($now),
            ],
        ];
    }

    public function appendMovement(
        string $id,
        string $homeId,
        string $homeProductId,
        string $movementType,
        string $quantityDelta,
        string $sourceType,
        string $sourceId,
        string $reason,
        string $actorUserId,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $recordedAt,
    ): array {
        $existing = $this->one(
            'SELECT id FROM stock_movements
             WHERE home_id = :home AND source_type = :source_type
               AND source_id = :source_id AND home_product_id = :product',
            [
                'home' => $homeId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'product' => $homeProductId,
            ],
        );
        if ($existing !== null) {
            return $this->movementResult($homeId, (string) $existing['id'], true);
        }
        $product = $this->one(
            $this->forUpdate('SELECT id, status FROM home_products
             WHERE home_id = :home AND id = :product'),
            ['home' => $homeId, 'product' => $homeProductId],
        );
        if ($product === null || (string) $product['status'] !== 'active') {
            throw new \DomainException('The selected home product is unavailable.');
        }
        // A matching request may have committed while this transaction waited
        // for the product lock. Recheck before appending the ledger entry.
        $existing = $this->one(
            'SELECT id FROM stock_movements
             WHERE home_id = :home AND source_type = :source_type
               AND source_id = :source_id AND home_product_id = :product',
            [
                'home' => $homeId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'product' => $homeProductId,
            ],
        );
        if ($existing !== null) {
            return $this->movementResult($homeId, (string) $existing['id'], true);
        }
        $recorded = $this->date($recordedAt);
        $this->connection->insert('stock_movements', [
            'id' => $id,
            'home_id' => $homeId,
            'home_product_id' => $homeProductId,
            'movement_type' => $movementType,
            'quantity_delta' => $quantityDelta,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reason' => $reason,
            'actor_user_id' => $actorUserId,
            'reversed_movement_id' => null,
            'occurred_at' => $this->date($occurredAt),
            'created_at' => $recorded,
        ]);
        $updated = $this->connection->executeStatement(
            'UPDATE inventory_balances
             SET quantity = quantity + :delta, last_movement_id = :movement,
                 revision = revision + 1, updated_at = :updated
             WHERE home_id = :home AND home_product_id = :product',
            [
                'delta' => $quantityDelta,
                'movement' => $id,
                'updated' => $recorded,
                'home' => $homeId,
                'product' => $homeProductId,
            ],
        );
        if ($updated === 0) {
            $this->connection->insert('inventory_balances', [
                'home_id' => $homeId,
                'home_product_id' => $homeProductId,
                'quantity' => $quantityDelta,
                'last_movement_id' => $id,
                'revision' => 1,
                'updated_at' => $recorded,
            ]);
        }

        return $this->movementResult($homeId, $id, false);
    }

    public function movements(string $homeId, ?string $homeProductId, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT sm.id, sm.home_product_id AS homeProductId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    sm.movement_type AS movementType, sm.quantity_delta AS quantityDelta,
                    sm.source_type AS sourceType, sm.source_id AS sourceId,
                    sm.reason, sm.actor_user_id AS actorUserId,
                    sm.occurred_at AS occurredAt, sm.created_at AS createdAt
             FROM stock_movements sm
             INNER JOIN home_products hp ON hp.id = sm.home_product_id AND hp.home_id = sm.home_id
             LEFT JOIN products p ON p.id = hp.product_id
             WHERE sm.home_id = :home
               AND (:product_empty = :empty OR sm.home_product_id = :product)
             ORDER BY sm.occurred_at DESC, sm.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            [
                'home' => $homeId,
                'product_empty' => $homeProductId ?? '',
                'product' => $homeProductId ?? '',
                'empty' => '',
            ],
        );
    }

    public function balance(string $homeId, string $homeProductId): ?array
    {
        return $this->one(
            'SELECT home_id AS homeId, home_product_id AS homeProductId,
                    quantity, last_movement_id AS lastMovementId,
                    revision, updated_at AS updatedAt
             FROM inventory_balances
             WHERE home_id = :home AND home_product_id = :product',
            ['home' => $homeId, 'product' => $homeProductId],
        );
    }

    public function createCountSession(
        string $id,
        string $homeId,
        ?string $locationId,
        string $notes,
        bool $scopeComplete,
        string $reliability,
        string $actorUserId,
        DateTimeImmutable $at,
    ): void {
        if ($locationId !== null) {
            $available = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM home_locations
                 WHERE id = :location AND home_id = :home AND status = :status',
                ['location' => $locationId, 'home' => $homeId, 'status' => 'active'],
            );
            if ($available !== 1) {
                throw new \DomainException('The selected location is unavailable.');
            }
        }
        $now = $this->date($at);
        $this->connection->insert('stock_count_sessions', [
            'id' => $id,
            'home_id' => $homeId,
            'location_id' => $locationId,
            'status' => 'open',
            'notes' => $notes,
            'scope_complete' => $scopeComplete,
            'reliability' => $reliability,
            'revision' => 1,
            'opened_by_user_id' => $actorUserId,
            'opened_at' => $now,
            'closed_by_user_id' => null,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function countSession(string $homeId, string $sessionId): ?array
    {
        return $this->one(
            'SELECT s.id, s.home_id AS homeId, s.location_id AS locationId,
                    l.name AS locationName, s.status, s.notes,
                    s.scope_complete AS scopeComplete, s.reliability, s.revision,
                    s.opened_by_user_id AS openedByUserId, s.opened_at AS openedAt,
                    s.closed_by_user_id AS closedByUserId, s.closed_at AS closedAt,
                    s.created_at AS createdAt, s.updated_at AS updatedAt
             FROM stock_count_sessions s
             LEFT JOIN home_locations l ON l.id = s.location_id AND l.home_id = s.home_id
             WHERE s.home_id = :home AND s.id = :id',
            ['home' => $homeId, 'id' => $sessionId],
        );
    }

    public function countSessions(string $homeId, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT s.id, s.location_id AS locationId, l.name AS locationName,
                    s.status, s.notes, s.scope_complete AS scopeComplete,
                    s.reliability, s.revision, s.opened_at AS openedAt,
                    s.closed_at AS closedAt, COUNT(sl.id) AS lineCount
             FROM stock_count_sessions s
             LEFT JOIN home_locations l ON l.id = s.location_id AND l.home_id = s.home_id
             LEFT JOIN stock_count_lines sl ON sl.session_id = s.id AND sl.home_id = s.home_id
             WHERE s.home_id = :home
             GROUP BY s.id, s.location_id, l.name, s.status, s.notes,
                      s.scope_complete, s.reliability, s.revision,
                      s.opened_at, s.closed_at
             ORDER BY s.opened_at DESC, s.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['home' => $homeId],
        );
    }

    public function countLines(string $homeId, string $sessionId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT sl.id, sl.home_product_id AS homeProductId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    COALESCE(pk.original_pack_text, hp.original_pack_text, :empty) AS packText,
                    sl.quantity, sl.confidence, sl.source, sl.notes, sl.status,
                    sl.revision, sl.counted_by_user_id AS countedByUserId,
                    sl.created_at AS createdAt, sl.updated_at AS updatedAt
             FROM stock_count_lines sl
             INNER JOIN home_products hp ON hp.id = sl.home_product_id AND hp.home_id = sl.home_id
             LEFT JOIN products p ON p.id = hp.product_id
             LEFT JOIN product_packs pk ON pk.id = hp.pack_id
             WHERE sl.home_id = :home AND sl.session_id = :session
             ORDER BY CASE WHEN sl.status = :confirmed THEN 1 ELSE 0 END,
                      productName, sl.id',
            ['empty' => '', 'home' => $homeId, 'session' => $sessionId, 'confirmed' => 'confirmed'],
        );
    }

    public function saveCountLine(
        string $id,
        string $homeId,
        string $sessionId,
        string $homeProductId,
        string $quantity,
        ?string $confidence,
        string $source,
        string $notes,
        string $actorUserId,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): bool {
        $product = $this->one(
            $this->forUpdate('SELECT id, status FROM home_products
             WHERE id = :product AND home_id = :home'),
            ['product' => $homeProductId, 'home' => $homeId],
        );
        if ($product === null || (string) $product['status'] !== 'active') {
            return false;
        }
        $session = $this->one(
            $this->forUpdate('SELECT id FROM stock_count_sessions
             WHERE id = :session AND home_id = :home AND status = :status'),
            ['session' => $sessionId, 'home' => $homeId, 'status' => 'open'],
        );
        if ($session === null) {
            return false;
        }
        $existing = $this->one(
            'SELECT id, revision FROM stock_count_lines
             WHERE home_id = :home AND session_id = :session AND home_product_id = :product',
            ['home' => $homeId, 'session' => $sessionId, 'product' => $homeProductId],
        );
        $now = $this->date($at);
        if ($existing === null) {
            if ($expectedRevision !== 0) {
                return false;
            }
            $this->connection->insert('stock_count_lines', [
                'id' => $id,
                'home_id' => $homeId,
                'session_id' => $sessionId,
                'home_product_id' => $homeProductId,
                'quantity' => $quantity,
                'confidence' => $confidence,
                'source' => $source,
                'notes' => $notes,
                'status' => 'confirmed',
                'revision' => 1,
                'counted_by_user_id' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            if ((string) $existing['id'] !== $id) {
                return false;
            }
            $updated = $this->connection->executeStatement(
                'UPDATE stock_count_lines
                 SET quantity = :quantity, confidence = :confidence, source = :source,
                     notes = :notes, counted_by_user_id = :actor,
                     revision = revision + 1, updated_at = :updated
                 WHERE id = :id AND home_id = :home AND session_id = :session
                   AND revision = :revision',
                [
                    'quantity' => $quantity,
                    'confidence' => $confidence,
                    'source' => $source,
                    'notes' => $notes,
                    'actor' => $actorUserId,
                    'updated' => $now,
                    'id' => $id,
                    'home' => $homeId,
                    'session' => $sessionId,
                    'revision' => $expectedRevision,
                ],
            );
            if ($updated !== 1) {
                return false;
            }
        }
        $sessionUpdated = $this->connection->executeStatement(
            'UPDATE stock_count_sessions
             SET revision = revision + 1, updated_at = :updated
             WHERE id = :session AND home_id = :home AND status = :status',
            ['updated' => $now, 'session' => $sessionId, 'home' => $homeId, 'status' => 'open'],
        );

        return $sessionUpdated === 1;
    }

    public function closeCountSession(
        string $homeId,
        string $sessionId,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        $now = $this->date($at);

        return $this->connection->executeStatement(
            'UPDATE stock_count_sessions
             SET status = :closed, closed_by_user_id = :actor, closed_at = :closed_at,
                 revision = revision + 1, updated_at = :updated
             WHERE id = :id AND home_id = :home AND status = :open
               AND revision = :revision',
            [
                'closed' => 'closed',
                'actor' => $actorUserId,
                'closed_at' => $now,
                'updated' => $now,
                'id' => $sessionId,
                'home' => $homeId,
                'open' => 'open',
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function cancelCountSession(
        string $homeId,
        string $sessionId,
        int $expectedRevision,
        string $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        $now = $this->date($at);

        return $this->connection->executeStatement(
            'UPDATE stock_count_sessions
             SET status = :cancelled, closed_by_user_id = :actor, closed_at = :closed_at,
                 revision = revision + 1, updated_at = :updated
             WHERE id = :id AND home_id = :home AND status = :open
               AND revision = :revision',
            [
                'cancelled' => 'cancelled',
                'actor' => $actorUserId,
                'closed_at' => $now,
                'updated' => $now,
                'id' => $sessionId,
                'home' => $homeId,
                'open' => 'open',
                'revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function rebuildBalances(string $homeId, DateTimeImmutable $at): array
    {
        $this->connection->delete('inventory_balances', ['home_id' => $homeId]);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT sm.home_product_id, SUM(sm.quantity_delta) AS quantity,
                    MAX(sm.id) AS last_movement_id
             FROM stock_movements sm
             WHERE sm.home_id = :home
             GROUP BY sm.home_product_id',
            ['home' => $homeId],
        );
        $now = $this->date($at);
        foreach ($rows as $row) {
            $this->connection->insert('inventory_balances', [
                'home_id' => $homeId,
                'home_product_id' => $row['home_product_id'],
                'quantity' => $row['quantity'],
                'last_movement_id' => $row['last_movement_id'],
                'revision' => 1,
                'updated_at' => $now,
            ]);
        }
        $total = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_balances WHERE home_id = :home',
            ['home' => $homeId],
        );

        return ['products' => count($rows), 'quantity' => (string) $total];
    }

    public function inventorySummary(string $homeId): array
    {
        $summary = $this->one(
            'SELECT
                (SELECT COUNT(*) FROM product_packs WHERE status <> :archived) AS itemMasterCount,
                (SELECT COUNT(*) FROM home_products
                 WHERE home_id = :home AND status = :active) AS countedProductCount,
                (SELECT COALESCE(SUM(quantity), 0) FROM inventory_balances
                 WHERE home_id = :home) AS countedQuantity,
                (SELECT COUNT(*) FROM inventory_balances ib
                 INNER JOIN stock_threshold_preferences sp
                   ON sp.home_id = ib.home_id AND sp.home_product_id = ib.home_product_id
                 WHERE ib.home_id = :home AND sp.never_suggest = :not_never
                   AND sp.minimum_quantity IS NOT NULL
                   AND ib.quantity < sp.minimum_quantity) AS belowConfiguredMinimumCount,
                (SELECT COUNT(*) FROM stock_count_sessions
                 WHERE home_id = :home AND status = :open) AS openCountSessionCount',
            [
                'archived' => 'archived',
                'home' => $homeId,
                'active' => 'active',
                'not_never' => false,
                'open' => 'open',
            ],
        ) ?? [];
        $summary['recentStock'] = $this->connection->fetchAllAssociative(
            'SELECT hp.id AS homeProductId,
                    COALESCE(p.canonical_name, hp.private_name) AS productName,
                    COALESCE(pk.original_pack_text, hp.original_pack_text, :empty) AS packText,
                    ib.quantity, ib.updated_at AS updatedAt
             FROM inventory_balances ib
             INNER JOIN home_products hp
               ON hp.id = ib.home_product_id AND hp.home_id = ib.home_id
             LEFT JOIN products p ON p.id = hp.product_id
             LEFT JOIN product_packs pk ON pk.id = hp.pack_id
             WHERE ib.home_id = :home
             ORDER BY ib.updated_at DESC, hp.id DESC
             LIMIT 8',
            ['empty' => '', 'home' => $homeId],
        );

        return $summary;
    }

    /** @return array<string, mixed> */
    private function movementResult(string $homeId, string $movementId, bool $replayed): array
    {
        $row = $this->one(
            'SELECT sm.id, sm.home_product_id AS homeProductId,
                    sm.quantity_delta AS quantityDelta, sm.movement_type AS movementType,
                    ib.quantity AS balance, ib.revision AS balanceRevision
             FROM stock_movements sm
             INNER JOIN inventory_balances ib
               ON ib.home_id = sm.home_id AND ib.home_product_id = sm.home_product_id
             WHERE sm.home_id = :home AND sm.id = :id',
            ['home' => $homeId, 'id' => $movementId],
        );
        if ($row === null) {
            throw new \RuntimeException('Committed stock movement is unavailable.');
        }
        $row['replayed'] = $replayed;

        return $row;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $parameters): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $parameters);

        return $row === false ? null : $row;
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function categoryRecord(array $row): array
    {
        $row['revision'] = (int) $row['revision'];
        $row['createdAt'] = $this->atom((string) $row['createdAt']);
        $row['updatedAt'] = $this->atom((string) $row['updatedAt']);
        $row['archivedAt'] = $row['archivedAt'] === null
            ? null
            : $this->atom((string) $row['archivedAt']);

        return $row;
    }

    private function atom(string $date): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function forUpdate(string $sql): string
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return $sql;
        }

        return $sql . ' FOR UPDATE';
    }
}
