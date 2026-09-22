#!/usr/bin/env python3
"""Keep storage-specific search in infrastructure and exercise the service boundary."""
from pathlib import Path
old = Path('src/Catalog/Application/CatalogMaintenanceSearch.php')
new = Path('src/Catalog/Infrastructure/Doctrine/CatalogMaintenanceSearch.php')
if old.exists():
    new.write_text(old.read_text().replace('namespace Providentia\\Catalog\\Application;',
        'namespace Providentia\\Catalog\\Infrastructure\\Doctrine;'))
    old.unlink()
p = Path('src/Catalog/Infrastructure/Doctrine/DbalCatalogGovernanceStore.php')
s = p.read_text().replace('use Providentia\\Catalog\\Application\\CatalogMaintenanceSearch;\n', '')
p.write_text(s)
p = Path('tests/Unit/Catalog/CatalogMaintenanceSearchTest.php')
s = p.read_text().replace('use Providentia\\Catalog\\Application\\CatalogMaintenanceSearch;',
    'use Providentia\\Catalog\\Infrastructure\\Doctrine\\CatalogMaintenanceSearch;')
p.write_text(s)
p = Path('tests/Unit/Catalog/CatalogMaintenanceServiceTest.php')
s = p.read_text()
if 'testReviewerSearchPreservesProductFilter' not in s:
    pos = s.rfind('\n}')
    s = s[:pos] + '''

    public function testReviewerSearchPreservesProductFilterAndPagination(): void
    {
        $store = $this->createMock(CatalogMaintenanceStore::class);
        $store->expects(self::once())->method('entities')
            ->with('pack', 100, 'product-id', 'Rice')
            ->willReturn([]);
        $service = new CatalogMaintenanceService(
            $store,
            new CatalogAuthorization(),
            new HomeFixedClock(new DateTimeImmutable('2026-09-22T12:00:00Z')),
            new RecordingTransactionManager(),
        );
        $identity = new AuthenticatedIdentity('user', 'session', 'device', null, [], ['catalog.review']);
        self::assertSame([], $service->list($identity, 'pack', 100, 'product-id', '  Rice  '));
    }

    public function testOversizedSearchCannotReachTheDatabase(): void
    {
        $store = $this->createMock(CatalogMaintenanceStore::class);
        $store->expects(self::never())->method('entities');
        $service = new CatalogMaintenanceService(
            $store,
            new CatalogAuthorization(),
            new HomeFixedClock(new DateTimeImmutable('2026-09-22T12:00:00Z')),
            new RecordingTransactionManager(),
        );
        $identity = new AuthenticatedIdentity('user', 'session', 'device', null, [], ['catalog.review']);
        try {
            $service->list($identity, 'category', 0, null, str_repeat('x', 192));
            self::fail('Oversized search was accepted.');
        } catch (Problem $problem) {
            self::assertSame(422, $problem->status);
        }
    }
''' + s[pos:]
p.write_text(s)
print('Literal search storage boundary and authorized service regressions complete.')
