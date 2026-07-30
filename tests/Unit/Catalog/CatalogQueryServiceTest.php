<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use Providentia\Catalog\Application\CatalogQueryService;
use Providentia\Catalog\Application\CatalogStore;

final class CatalogQueryServiceTest extends TestCase
{
    public function testQueryAndPaginationAreNormalizedAtTheApplicationBoundary(): void
    {
        $catalog = $this->createMock(CatalogStore::class);
        $catalog->expects(self::once())
            ->method('search')
            ->with(str_repeat('x', 191), 100, 0)
            ->willReturn([['id' => 'product-1']]);
        $service = new CatalogQueryService($catalog);

        $result = $service->search('  ' . str_repeat('x', 220) . ' ', 500, -20);

        self::assertSame([['id' => 'product-1']], $result);
    }

    public function testLimitHasAOneRecordFloor(): void
    {
        $catalog = $this->createMock(CatalogStore::class);
        $catalog->expects(self::once())
            ->method('search')
            ->with('', 1, 4)
            ->willReturn([]);

        self::assertSame([], (new CatalogQueryService($catalog))->search(' ', 0, 4));
    }
}
