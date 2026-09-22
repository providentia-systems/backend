from pathlib import Path
root = Path(__file__).resolve().parents[1]
p = root / 'src/Inventory/Application/InventoryService.php'
s = p.read_text().replace("$productId === null && $packId === null && ($privateName === null || $privateName === '')", "$productId === null && $packId === null && $privateName === null")
p.write_text(s)
p = root / 'tests/Integration/InventoryItemMasterTest.php'
s = p.read_text().replace("        self::assertSame(1, (int) $this->store->homeProduct(self::HOME_ID, self::HOME_PRODUCT_ID)['revision']);", "        $unchanged = $this->store->homeProduct(self::HOME_ID, self::HOME_PRODUCT_ID);\n        self::assertNotNull($unchanged);\n        self::assertArrayHasKey('revision', $unchanged);\n        self::assertSame(1, (int) $unchanged['revision']);")
p.write_text(s)
