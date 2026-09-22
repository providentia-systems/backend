#!/usr/bin/env python3
"""Apply bounded catalog search, preserving tenant and moderation boundaries."""
from pathlib import Path
import gzip
import hashlib
import json
import re
import subprocess

for name in ['src/Catalog/Application/CatalogMaintenanceStore.php', 'src/Catalog/Infrastructure/Doctrine/DbalCatalogGovernanceStore.php']:
    p = Path(name)
    s = p.read_text()
    s = s.replace('public function entities(string $type, int $offset, ?string $productId = null): array', '''public function entities(
        string $type,
        int $offset,
        ?string $productId = null,
        string $query = '',
    ): array''')
    if 'Infrastructure' in name and 'CatalogMaintenanceSearch::where' not in s:
        s = s.replace('use Providentia\\Catalog\\Application\\CatalogMaintenanceStore;', 'use Providentia\\Catalog\\Application\\CatalogMaintenanceStore;\nuse Providentia\\Catalog\\Application\\CatalogMaintenanceSearch;')
        start = s.index('    public function entities(')
        end = s.index('    /** @param array<string, mixed> $row', start)
        part = s[start:end]
        part = part.replace('        $rows = $this->connection->fetchAllAssociative(', '''        $params = $productId === null ? [] : ['product' => $productId];
        $search = CatalogMaintenanceSearch::where($query, $definition['fields']);
        if ($search['sql'] !== '') {
            $where[] = $search['sql'];
            $params += $search['params'];
        }
        $rows = $this->connection->fetchAllAssociative(''')
        part = part.replace("            $productId === null ? [] : ['product' => $productId],", '            $params,')
        s = s[:start] + part + s[end:]
    p.write_text(s)

Path('src/Catalog/Application/CatalogMaintenanceSearch.php').write_text('''<?php

declare(strict_types=1);

namespace Providentia\\Catalog\\Application;

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
''')
p = Path('src/Catalog/Application/CatalogMaintenanceService.php')
s = p.read_text().replace('public function list(AuthenticatedIdentity $identity, string $type, int $offset, ?string $productId = null): array', '''public function list(
        AuthenticatedIdentity $identity,
        string $type,
        int $offset,
        ?string $productId = null,
        string $query = '',
    ): array''')
s = s.replace('        return $this->store->entities($type, max(0, $offset), $productId);', '''        if (mb_strlen($query) > 191) {
            throw new Problem(422, 'Invalid catalog search', 'Search text must not exceed 191 characters.');
        }
        return $this->store->entities($type, max(0, $offset), $productId, trim($query));''')
p.write_text(s)
p = Path('src/Catalog/Http/CatalogMaintenanceHandler.php')
s = p.read_text()
if "$query = $request->getQueryParams()['q']" not in s:
    s = s.replace("        if ($request->getMethod() === 'GET') {", "        if ($request->getMethod() === 'GET') {\n            $query = $request->getQueryParams()['q'] ?? '';\n            if (! is_string($query)) {\n                throw new HttpProblem(422, 'Invalid search', 'Catalog search must be plain text.');\n            }")
    s = s.replace("                        ? (string) $request->getQueryParams()['productId'] : null,", "                        ? (string) $request->getQueryParams()['productId'] : null,\n                    $query,")
p.write_text(s)

Path('tests/Unit/Catalog/CatalogMaintenanceSearchTest.php').write_text('''<?php

declare(strict_types=1);

namespace ProvidentiaTest\\Unit\\Catalog;

use Doctrine\\DBAL\\DriverManager;
use InvalidArgumentException;
use PHPUnit\\Framework\\TestCase;
use Providentia\\Catalog\\Application\\CatalogMaintenanceSearch;

final class CatalogMaintenanceSearchTest extends TestCase
{
    public function testLiteralSearchRunsBeforePaginationAndPreservesTheExistingScope(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE identities (id TEXT, canonical_name TEXT, scope TEXT)');
        for ($i = 0; $i < 205; $i++) {
            $db->insert('identities', [
                'id' => sprintf('%04d', $i),
                'canonical_name' => $i >= 101 ? 'Milk 50%_!' : 'Other product',
                'scope' => 'global',
            ]);
        }
        $db->insert('identities', ['id' => 'secret', 'canonical_name' => 'Milk 50%_!', 'scope' => 'home']);
        $search = CatalogMaintenanceSearch::where('  MILK 50%_!  ', [
            'canonicalName' => ['canonical_name', 191, false],
        ]);
        $sql = "SELECT id FROM identities WHERE scope = 'global' AND " . $search['sql'] . ' ORDER BY id';
        $first = $db->fetchFirstColumn($sql . ' LIMIT 100 OFFSET 0', $search['params']);
        $second = $db->fetchFirstColumn($sql . ' LIMIT 100 OFFSET 100', $search['params']);
        self::assertCount(100, $first);
        self::assertCount(4, $second);
        self::assertSame('0101', $first[0]);
        self::assertSame('0204', $second[3]);
        self::assertSame([], array_intersect($first, $second));
        self::assertNotContains('secret', [...$first, ...$second]);
        self::assertSame([], $db->fetchFirstColumn($sql . ' LIMIT 100 OFFSET 200', $search['params']));
        $db->close();
    }

    public function testEmptySearchLeavesTheOriginalQueryUntouched(): void
    {
        self::assertSame(['sql' => '', 'params' => []], CatalogMaintenanceSearch::where(' ', []));
    }

    public function testQueryDataNeverBecomesSqlSyntaxOrAnUnboundWildcard(): void
    {
        $search = CatalogMaintenanceSearch::where("%' OR 1=1 --", []);
        self::assertStringNotContainsString('OR 1=1', $search['sql']);
        self::assertSame("%!%' or 1=1 --%", $search['params']['catalog_query_0']);
    }

    public function testUntrustedColumnDefinitionsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CatalogMaintenanceSearch::where('milk', ['name' => ['name) OR 1=1', 191, false]]);
    }

    public function testOverlongSearchIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CatalogMaintenanceSearch::where(str_repeat('x', 192), []);
    }
}
''')
# Add an optional query parameter without changing existing resource shapes.
archive = Path('contracts/source/providentia-v1.json.gz')
old_zip = archive.read_bytes()
old_json = gzip.decompress(old_zip)
contract = json.loads(old_json)
paths = [(name, value['get']) for name, value in contract['paths'].items()
         if name == '/api/v1/catalog-admin/entities/{entityType}' and 'get' in value]
assert len(paths) == 1, 'The exact catalog maintenance list contract is required.'
parameters = paths[0][1].setdefault('parameters', [])
if not any(p.get('name') == 'q' and p.get('in') == 'query' for p in parameters):
    parameters.append({'name': 'q', 'in': 'query', 'required': False,
        'description': 'Literal, case-insensitive identity search applied before offset pagination. Empty text lists all authorized records.',
        'schema': {'type': 'string', 'maxLength': 191, 'default': ''}})
    new_json = (json.dumps(contract, ensure_ascii=False, indent=2) + '\n').encode()
    new_zip = gzip.compress(new_json, mtime=0)
    archive.write_bytes(new_zip)
    output = Path('contracts/openapi/providentia-v1.json')
    output.write_bytes(new_json)
    replacements = {hashlib.sha256(old_json).hexdigest(): hashlib.sha256(new_json).hexdigest(),
                    hashlib.sha256(old_zip).hexdigest(): hashlib.sha256(new_zip).hexdigest()}
    for filename in subprocess.check_output(['git', 'ls-files'], text=True).splitlines():
        p = Path(filename)
        if not p.is_file() or p == archive or p == output or 'handover-next.py' in filename:
            continue
        try:
            text = p.read_text()
        except (UnicodeError, OSError):
            continue
        updated = text
        for before, after in replacements.items():
            updated = updated.replace(before, after)
        if updated != text:
            p.write_text(updated)
            print('Updated contract digest pin:', filename)
    print('Paired contract hashes:', json.dumps(replacements))
Path('docs/catalog-maintenance-search.md').write_text('''# Catalog maintenance search

GET `/api/v1/catalog-admin/entities/{entityType}` accepts optional `q`, bounded
to 191 characters. Literal case-insensitive matching runs on the database before
the existing 100-row offset pagination. SQL wildcard characters in entered
text remain literal. Entity UUIDs and ordinary identity fields are searchable;
rule JSON and provenance are not searched. Existing role checks, product filters
and global-alias isolation remain in effect. This does not expose home aliases.

The backend-owned OpenAPI document and digest pins include the optional query;
paired Admin and Client contract copies must carry the same document digest.
''')
print('Catalog maintenance search and regression coverage applied.')
