<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Infrastructure\Doctrine\CatalogMaintenanceSearch;

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
