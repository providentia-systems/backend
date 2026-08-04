<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Providentia\DataGovernance\Application\DataExportGenerator;

final readonly class DbalJsonDataExportGenerator implements DataExportGenerator
{
    public function __construct(private Connection $connection, private int $pageSize = 250)
    {
    }

    public function generate(array $request): string
    {
        $scope = (string) $request['scopeType'];
        $data = $scope === 'account'
            ? $this->account((string) $request['subjectUserId'])
            : $this->home((string) $request['homeId']);

        return json_encode([
            'format' => 'providentia-data-export-v1',
            'requestId' => (string) $request['id'],
            'scope' => $scope,
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /** @return array<string, mixed> */
    private function account(string $userId): array
    {
        return [
            'account' => $this->page(
                'SELECT id, email, status, email_verified_at, created_at, updated_at
                 FROM users WHERE id = :scope',
                $userId,
            ),
            'profile' => $this->page(
                'SELECT user_id, display_name, locale, timezone, created_at, updated_at
                 FROM user_profiles WHERE user_id = :scope',
                $userId,
            ),
            'devices' => $this->page(
                'SELECT id, name, platform, last_seen_at, revoked_at, created_at
                 FROM devices WHERE user_id = :scope ORDER BY id',
                $userId,
            ),
            'memberships' => $this->page(
                'SELECT home_id, role, status, revision, joined_at, left_at, updated_at
                 FROM home_memberships WHERE user_id = :scope ORDER BY home_id',
                $userId,
            ),
            'catalogContributions' => $this->page(
                'SELECT id, contribution_type, payload_json, moderation_status, revision, created_at
                 FROM catalog_contributions WHERE submitted_by_user_id = :scope ORDER BY id',
                $userId,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function home(string $homeId): array
    {
        $queries = [
            'home' => 'SELECT * FROM homes WHERE id = :scope',
            'memberships' => 'SELECT * FROM home_memberships WHERE home_id = :scope ORDER BY user_id',
            'locations' => 'SELECT * FROM home_locations WHERE home_id = :scope ORDER BY id',
            'products' => 'SELECT * FROM home_products WHERE home_id = :scope ORDER BY id',
            'balances' => 'SELECT * FROM inventory_balances WHERE home_id = :scope ORDER BY home_product_id',
            'movements' => 'SELECT * FROM stock_movements WHERE home_id = :scope ORDER BY id',
            'countSessions' => 'SELECT * FROM stock_count_sessions WHERE home_id = :scope ORDER BY id',
            'countLines' => 'SELECT l.* FROM stock_count_lines l
                INNER JOIN stock_count_sessions s ON s.id = l.session_id
                WHERE s.home_id = :scope ORDER BY l.id',
            'receipts' => 'SELECT * FROM receipts WHERE home_id = :scope ORDER BY id',
            'receiptLines' => 'SELECT l.* FROM receipt_lines l
                INNER JOIN receipts r ON r.id = l.receipt_id
                WHERE r.home_id = :scope ORDER BY l.id',
            'prices' => 'SELECT * FROM price_observations WHERE home_id = :scope ORDER BY id',
            'shoppingLists' => 'SELECT * FROM shopping_lists WHERE home_id = :scope ORDER BY id',
            'shoppingListLines' => 'SELECT l.* FROM shopping_list_lines l
                INNER JOIN shopping_lists s ON s.id = l.shopping_list_id
                WHERE s.home_id = :scope ORDER BY l.id',
            'aiExtractions' => 'SELECT * FROM ai_extractions WHERE home_id = :scope ORDER BY id',
            'aiCandidates' => 'SELECT c.* FROM ai_extraction_candidates c
                INNER JOIN ai_extractions e ON e.id = c.extraction_id
                WHERE e.home_id = :scope ORDER BY c.extraction_id, c.position',
            'suggestions' => 'SELECT * FROM shopping_suggestions WHERE home_id = :scope ORDER BY id',
            'catalogContributions' => 'SELECT id, contribution_type, payload_json,
                moderation_status, revision, created_at FROM catalog_contributions
                WHERE home_id = :scope ORDER BY id',
        ];
        $data = [];
        foreach ($queries as $name => $query) {
            $data[$name] = $this->page($query, $homeId);
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function page(string $sql, string $scope): array
    {
        $rows = [];
        for ($offset = 0; $offset < 250000; $offset += $this->pageSize) {
            $page = $this->connection->fetchAllAssociative(
                $sql . ' LIMIT :limit OFFSET :offset',
                ['scope' => $scope, 'limit' => $this->pageSize, 'offset' => $offset],
                ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
            );
            array_push($rows, ...$page);
            if (count($page) < $this->pageSize) {
                return $rows;
            }
        }

        throw new \RuntimeException(
            'A data-export section exceeded the configured 250,000-row safety ceiling.',
        );
    }
}
