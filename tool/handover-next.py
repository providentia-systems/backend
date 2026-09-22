#!/usr/bin/env python3
"""Correct the test fixture without relaxing catalog identifier validation."""
from pathlib import Path
p = Path('tests/Unit/Catalog/CatalogMaintenanceServiceTest.php')
s = p.read_text()
a = s.index('    public function testReviewerSearchPreservesProductFilterAndPagination(): void')
b = s.index('    public function testOversizedSearchCannotReachTheDatabase()', a)
part = s[a:b]
part = part.replace("        $store = $this->createMock(CatalogMaintenanceStore::class);", "        $productId = '0198a0b1-c2d3-7e4f-8123-456789abcdef';\n        $store = $this->createMock(CatalogMaintenanceStore::class);", 1)
part = part.replace("'product-id'", '$productId')
s = s[:a] + part + s[b:]
p.write_text(s)
print('Search scoping fixture now uses a valid catalog UUID.')
