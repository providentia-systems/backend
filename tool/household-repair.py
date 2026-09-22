"""Temporary reviewed source corrections; removed before the final PR head."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
def edit(name, old, new):
    path = root / name
    source = path.read_text()
    if new in source:
        return
    if old not in source:
        raise RuntimeError('Expected source not found: ' + name)
    path.write_text(source.replace(old, new))
edit('src/Inventory/Application/InventoryService.php',
     "        if ($globalCategoryId !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $globalCategoryId) !== 1) {",
     "        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';\n        if ($globalCategoryId !== null && preg_match($uuidPattern, $globalCategoryId) !== 1) {")
edit('src/Inventory/Application/InventoryService.php',
     "            'category-conflict' => throw new Problem(422, 'Invalid category', 'Choose a global or a local category, not both.'),",
     "            'category-conflict' => throw new Problem(\n                422,\n                'Invalid category',\n                'Choose a global or a local category, not both.',\n            ),")
edit('src/Inventory/Infrastructure/Doctrine/DbalInventoryStore.php',
     'COALESCE(hp.normalized_private_name, p.normalized_name) AS sortName, p.normalized_brand AS sortBrand',
     'COALESCE(hp.normalized_private_name, p.normalized_name) AS sortName,\n                         p.normalized_brand AS sortBrand')
edit('src/Inventory/Infrastructure/Doctrine/DbalInventoryStore.php',
     'hc.id AS homeCategoryId, COALESCE(hc.name, gc.canonical_name, c.canonical_name) AS categoryName,',
     'hc.id AS homeCategoryId,\n                         COALESCE(hc.name, gc.canonical_name, c.canonical_name) AS categoryName,')
edit('src/Synchronization/Application/SyncCommandValidator.php',
     "['productId', 'packId', 'privateName', 'originalPackText', 'homeCategoryId', 'globalCategoryId', 'unit']",
     "['productId', 'packId', 'privateName', 'originalPackText',\n                    'homeCategoryId', 'globalCategoryId', 'unit']")
edit('migrations/Version20260922000100.php',
     "        $products->addForeignKeyConstraint('categories', ['global_category_id'], ['id'], [], 'fk_home_product_global_category');",
     "        $products->addForeignKeyConstraint(\n            'categories',\n            ['global_category_id'],\n            ['id'],\n            [],\n            'fk_home_product_global_category',\n        );")
edit('tests/Integration/InventoryItemMasterTest.php',
     "'CREATE TABLE categories (id TEXT PRIMARY KEY, canonical_name TEXT NOT NULL)'",
     "'CREATE TABLE categories (\n                id TEXT PRIMARY KEY, canonical_name TEXT NOT NULL, status TEXT NOT NULL DEFAULT \\'published\\'\n            )'")
edit('tests/Integration/InventoryItemMasterTest.php',
     "        self::assertSame($beforePacks, $this->connection->fetchAllAssociative('SELECT * FROM product_packs ORDER BY id'));",
     "        self::assertSame(\n            $beforePacks,\n            $this->connection->fetchAllAssociative('SELECT * FROM product_packs ORDER BY id'),\n        );")
edit('tests/Integration/InventoryItemMasterTest.php',
     "        self::assertSame($beforeBalances, $this->connection->fetchAllAssociative('SELECT * FROM inventory_balances ORDER BY home_id'));",
     "        self::assertSame(\n            $beforeBalances,\n            $this->connection->fetchAllAssociative('SELECT * FROM inventory_balances ORDER BY home_id'),\n        );")
path = root / 'tests/Unit/Inventory/InventoryServiceTest.php'
source = path.read_text()
start = source.index('    public function testHouseholdMetadataIsForwarded')
end = source.index('    private function', start)
source = source[:start] + source[start:end].replace('self::MOVEMENT_ID', "'01912345-6789-7abc-8def-9123456789ab'") + source[end:]
path.write_text(source)
edit('tests/Integration/Platform/PlatformAccessWorkflowTest.php',
     "new Version('Providentia\\Migrations\\Version20260912000100')",
     "new Version('Providentia\\Migrations\\Version20260922000100')")
for path in (root / 'tests/Integration').rglob('*.php'):
    source = path.read_text()
    source = source.replace("CREATE TABLE home_products (global_category_id TEXT, unit TEXT NOT NULL DEFAULT \\'units\\', ",
                            "CREATE TABLE home_products (global_category_id TEXT, unit TEXT NOT NULL DEFAULT \\'units\\',\n                ")
    path.write_text(source)
