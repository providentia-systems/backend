<?php

declare(strict_types=1);

namespace Providentia\Administration\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Providentia\Administration\Application\OperatorWorkspaceStore;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Infrastructure\Doctrine\DecimalProjection;

final class DbalOperatorWorkspaceStore implements OperatorWorkspaceStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function homes(string $search, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            ('SELECT h.*, a.group_id AS groupId, a.revision AS '
                . 'groupAssignmentRevision, g.name AS groupName
             FROM homes h '
                . 'LEFT JOIN access_assignments a ON a.subject_id = h.id AND a.scope = '
                . ':scope
             LEFT JOIN access_groups g ON g.id = a.group_id WHERE'
                . ' LOWER(h.name) LIKE :search
             ORDER BY h.name, h.id LIMIT 100'
                . ' OFFSET ') . max(0, $offset),
            [
                'scope' => 'home',
                'search' => '%' . mb_strtolower(
                    str_replace(['%', '_'], '', mb_substr($search, 0, 100)),
                ) . '%',
            ],
        );
    }

    public function home(string $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM homes WHERE id = ?', [$id]);
        if ($row === false) {
            return null;
        }
        $row['sharingConsent'] = $this->connection->fetchAssociative(
            'SELECT * FROM catalog_contribution_consents WHERE home_id = ?',
            [$id],
        ) ?: null;
        return $row;
    }

    public function records(
        string $homeId,
        string $collection,
        int $offset,
    ): array {
        if ($collection === 'products') {
            return $this->productRecords($homeId, $offset);
        }
        if ($collection === 'stock') {
            return $this->stockRecords($homeId, $offset);
        }
        if ($collection === 'sharing') {
            return $this->connection->fetchAllAssociative(
                'SELECT id, contribution_type, moderation_status, revision, created_at, updated_at '
                . 'FROM catalog_contributions WHERE home_id = ? ORDER BY id LIMIT 100 OFFSET ' . max(0, $offset),
                [$homeId],
            );
        }
        // Enumerated tables only: no arbitrary SQL, token or credential access.
        $table = match ($collection) {
            'categories' => 'home_categories',
            'locations' => 'home_locations',
            'stores' => 'stores',
            'movements' => 'stock_movements',
            'receipts' => 'receipts',
            'receipt-lines' => 'receipt_lines',
            'prices' => 'price_observations',
            'shopping-lists' => 'shopping_lists',
            'shopping-lines' => 'shopping_list_lines',
            'invitations' => 'home_invitations',
            'memberships' => 'home_memberships',
            default => throw new Problem(
                422,
                'Unknown collection',
                'Choose a supported home record collection.',
            ),
        };
        if ($collection === 'memberships') {
            return $this->connection->fetchAllAssociative(
                ('SELECT m.*, p.display_name AS displayName, u.email, p.avatar_source AS '
                    . 'avatarSource,
                p.avatar_revision AS avatarRevision FROM '
                    . 'home_memberships m INNER JOIN users u ON u.id = m.user_id'
                    . '
                LEFT JOIN user_profiles p ON p.user_id = m.user_id '
                    . 'WHERE m.home_id = ? ORDER BY m.user_id LIMIT 100 OFFSET ')
                    . max(0, $offset),
                [$homeId],
            );
        }
        $columns = $collection === 'invitations'
            ? 'id, home_id, normalized_email, role, status, revision, created_at, expires_at'
            : '*';
        return $this->connection->fetchAllAssociative(
            'SELECT ' . $columns . ' FROM ' . $table . ' WHERE home_id = ? ORDER BY id'
                . ' LIMIT 100 OFFSET ' . max(0, $offset),
            [$homeId],
        );
    }

    /** @return list<array<string, mixed>> */
    private function productRecords(string $homeId, int $offset): array
    {
        // Preserve the raw household fields for revision-bound editing. The
        // resolved columns are read-only, including archived catalog identities.
        return $this->connection->fetchAllAssociative(
            'SELECT hp.*, ' . $this->resolvedProductColumns() . '
             FROM home_products hp
             LEFT JOIN products p ON p.id = hp.product_id
             LEFT JOIN product_packs pk ON pk.id = hp.pack_id AND pk.product_id = hp.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN categories gc ON gc.id = hp.global_category_id
             LEFT JOIN home_categories hc ON hc.id = hp.home_category_id AND hc.home_id = hp.home_id
             WHERE hp.home_id = ? ORDER BY hp.id LIMIT 100 OFFSET ' . max(0, $offset),
            [$homeId],
        );
    }

    /** @return list<array<string, mixed>> */
    private function stockRecords(string $homeId, int $offset): array
    {
        // The balance population, ordering and raw evidence are unchanged.
        // Missing/archived references must remain inspectable; never join a
        // product or local category belonging to a different household.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ib.*, hp.product_id, hp.pack_id, hp.home_category_id, hp.global_category_id,
                hp.unit, ' . $this->resolvedProductColumns() . '
             FROM inventory_balances ib
             LEFT JOIN home_products hp ON hp.id = ib.home_product_id AND hp.home_id = ib.home_id
             LEFT JOIN products p ON p.id = hp.product_id
             LEFT JOIN product_packs pk ON pk.id = hp.pack_id AND pk.product_id = hp.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN categories gc ON gc.id = hp.global_category_id
             LEFT JOIN home_categories hc ON hc.id = hp.home_category_id AND hc.home_id = ib.home_id
             WHERE ib.home_id = ? ORDER BY ib.home_product_id LIMIT 100 OFFSET ' . max(0, $offset),
            [$homeId],
        );

        return array_map(static function (array $row): array {
            // Preserve raw field names while keeping decimal transport stable
            // across exact-decimal servers and SQLite numeric affinity.
            $row['quantity'] = DecimalProjection::string($row['quantity']);
            if (array_key_exists('pending_quantity', $row)) {
                $row['pending_quantity'] = DecimalProjection::string($row['pending_quantity']);
            }

            return $row;
        }, $rows);
    }

    private function resolvedProductColumns(): string
    {
        return "COALESCE(NULLIF(TRIM(hp.private_name), ''), NULLIF(TRIM(p.canonical_name), ''),
                    'Unresolved product') AS name,
                COALESCE(p.brand, '') AS brand,
                COALESCE(NULLIF(TRIM(hp.original_pack_text), ''), NULLIF(TRIM(pk.original_pack_text), ''),
                    'Not specified') AS pack_text,
                CASE WHEN hp.home_category_id IS NOT NULL
                    THEN COALESCE(hc.name, 'Unavailable local category')
                    WHEN hp.global_category_id IS NOT NULL
                    THEN COALESCE(gc.canonical_name, 'Unavailable global category')
                    ELSE COALESCE(c.canonical_name, 'Uncategorized') END AS category_name,
                CASE WHEN hp.home_category_id IS NOT NULL THEN 'Local'
                    WHEN hp.global_category_id IS NOT NULL OR c.id IS NOT NULL THEN 'Global'
                    ELSE '' END AS category_scope,
                CASE WHEN hp.id IS NULL THEN 'Missing home product'
                    WHEN hp.product_id IS NOT NULL AND p.id IS NULL THEN 'Missing catalog product'
                    WHEN hp.pack_id IS NOT NULL AND pk.id IS NULL THEN 'Missing or mismatched catalog pack'
                    WHEN hp.product_id IS NOT NULL AND hp.pack_id IS NULL THEN 'Catalog product; no pack selected'
                    WHEN hp.product_id IS NOT NULL THEN 'Catalog linked'
                    ELSE 'Private product' END AS catalog_reference,
                hp.status AS home_product_status,
                p.status AS catalog_product_status,
                pk.status AS catalog_pack_status";
    }

    public function administrators(): array
    {
        return $this->connection->fetchAllAssociative(
            ('SELECT u.id AS user_id, COALESCE(r.status, \'approved\') AS status,'
                . '
                    COALESCE(r.revision, 1) AS revision, r.created_at,'
                . '
                    u.email, p.display_name AS displayName, a.group_id '
                . 'AS groupId,
                    a.revision AS groupAssignmentRevision, '
                . 'g.name AS groupName,
                    CASE WHEN b.user_id IS NULL '
                . 'THEN 0 ELSE 1 END AS systemOwner
             FROM users u LEFT JOIN '
                . 'administrator_requests r ON r.user_id = u.id
             LEFT JOIN '
                . 'system_owner_bootstrap b ON b.user_id = u.id
             LEFT JOIN '
                . 'user_profiles p ON p.user_id = u.id
             LEFT JOIN '
                . 'access_assignments a ON a.scope = :scope AND a.subject_id = u.id'
                . '
             LEFT JOIN access_groups g ON g.id = a.group_id'
                . '
             WHERE r.user_id IS NOT NULL OR b.user_id IS NOT NULL ORDER'
                . ' BY r.created_at DESC'),
            ['scope' => 'admin'],
        );
    }

    public function reviewAdministrator(
        string $userId,
        string $actorId,
        string $status,
        int $revision,
        string $now,
    ): bool {
        return $this->connection->update(
            'administrator_requests',
            [
                'status' => $status,
                'reviewer_user_id' => $actorId,
                'revision' => $revision + 1,
                'updated_at' => $now,
            ],
            ['user_id' => $userId, 'revision' => $revision],
        ) === 1;
    }
}
