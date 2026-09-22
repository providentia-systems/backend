<?php

declare(strict_types=1);

namespace Providentia\Catalog\Infrastructure\Doctrine;

use InvalidArgumentException;

/** Produces bound literal search predicates from the closed entity definition. */
final class CatalogMaintenanceSearch
{
    /** @param array<string, array{string, int, bool}> $fields
     * @return array{sql: string, params: array<string, string>}
     */
    public static function where(string $query, array $fields): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['sql' => '', 'params' => []];
        }
        if (mb_strlen($query) > 191) {
            throw new InvalidArgumentException('Catalog search is limited to 191 characters.');
        }
        $value = '%' . strtr(mb_strtolower($query), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $columns = ['id'];
        foreach ($fields as [$column]) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $column) !== 1) {
                throw new InvalidArgumentException('Search columns must come from the catalog definition.');
            }
            // JSON and free-form rule provenance are not normal identity search fields.
            if (! in_array($column, ['attributes_json', 'rule_definition', 'provenance'], true)) {
                $columns[] = $column;
            }
        }
        $conditions = [];
        $params = [];
        foreach (array_unique($columns) as $index => $column) {
            $parameter = 'catalog_query_' . $index;
            $conditions[] = "LOWER($column) LIKE :$parameter ESCAPE '!'";
            $params[$parameter] = $value;
        }
        return ['sql' => '(' . implode(' OR ', $conditions) . ')', 'params' => $params];
    }
}
