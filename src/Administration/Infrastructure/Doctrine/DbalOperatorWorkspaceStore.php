<?php

declare(strict_types=1);

namespace Providentia\Administration\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Providentia\Administration\Application\OperatorWorkspaceStore;
use Providentia\SharedKernel\Application\Problem;

final class DbalOperatorWorkspaceStore implements OperatorWorkspaceStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function homes(string $search, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT h.*, a.group_id AS groupId, a.revision AS groupAssignmentRevision, g.name AS groupName
             FROM homes h LEFT JOIN access_assignments a ON a.subject_id = h.id AND a.scope = :scope
             LEFT JOIN access_groups g ON g.id = a.group_id WHERE LOWER(h.name) LIKE :search
             ORDER BY h.name, h.id LIMIT 100 OFFSET ' . max(0, $offset),
            ['scope' => 'home', 'search' => '%' . mb_strtolower(str_replace(['%', '_'], '', mb_substr($search, 0, 100))) . '%'],
        );
    }

    public function home(string $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM homes WHERE id = ?', [$id]);
        return $row === false ? null : $row;
    }

    public function records(string $homeId, string $collection, int $offset): array
    {
        // Enumerated tables only: no arbitrary SQL, token or credential access.
        $table = match ($collection) {
            'categories' => 'home_categories', 'products' => 'home_products',
            'locations' => 'home_locations', 'stock' => 'inventory_balances',
            'movements' => 'stock_movements', 'receipts' => 'receipts',
            'receipt-lines' => 'receipt_lines', 'prices' => 'price_observations',
            'shopping-lists' => 'shopping_lists', 'shopping-lines' => 'shopping_list_lines',
            'invitations' => 'home_invitations', 'memberships' => 'home_memberships',
            default => throw new Problem(422, 'Unknown collection', 'Choose a supported home record collection.'),
        };
        if ($collection === 'memberships') {
            return $this->connection->fetchAllAssociative('SELECT m.*, p.display_name AS displayName, u.email, p.avatar_source AS avatarSource,
                p.avatar_revision AS avatarRevision FROM home_memberships m INNER JOIN users u ON u.id = m.user_id
                LEFT JOIN user_profiles p ON p.user_id = m.user_id WHERE m.home_id = ? ORDER BY m.user_id LIMIT 100 OFFSET ' . max(0, $offset), [$homeId]);
        }
        $columns = $collection === 'invitations' ? 'id, home_id, normalized_email, role, status, revision, created_at, expires_at' : '*';
        $order = $collection === 'stock' ? 'home_product_id, location_id' : 'id';
        return $this->connection->fetchAllAssociative('SELECT ' . $columns . ' FROM ' . $table . ' WHERE home_id = ? ORDER BY ' . $order . ' LIMIT 100 OFFSET ' . max(0, $offset), [$homeId]);
    }

    public function administrators(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT r.*, u.email, p.display_name AS displayName, a.group_id AS groupId,
                    a.revision AS groupAssignmentRevision, g.name AS groupName
             FROM administrator_requests r INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN access_assignments a ON a.scope = :scope AND a.subject_id = u.id
             LEFT JOIN access_groups g ON g.id = a.group_id ORDER BY r.created_at DESC', ['scope' => 'admin'],
        );
    }

    public function reviewAdministrator(string $userId, string $actorId, string $status, int $revision, string $now): bool
    {
        return $this->connection->update('administrator_requests', ['status' => $status, 'reviewer_user_id' => $actorId, 'revision' => $revision + 1, 'updated_at' => $now], ['user_id' => $userId, 'revision' => $revision]) === 1;
    }
}
