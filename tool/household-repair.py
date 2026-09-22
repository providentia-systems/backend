"""Temporary exact source corrections; removed before the final PR head."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
p=root/'src/Inventory/Infrastructure/Doctrine/DbalInventoryStore.php'
s=p.read_text().replace('hc.id AS homeCategoryId, COALESCE(hc.name, gc.canonical_name, c.canonical_name) AS categoryName,','hc.id AS homeCategoryId,\n                         COALESCE(hc.name, gc.canonical_name, c.canonical_name) AS categoryName,');p.write_text(s)
p=root/'tests/Unit/Inventory/InventoryServiceTest.php';s=p.read_text().replace("'globalCategoryId' => '01912345-6789-7abc-8def-9123456789ab', 'unit'", "'globalCategoryId' => '01912345-6789-7abc-8def-9123456789ab',\n            'unit'").replace("$data['privateName'] === 'My name' && $data['globalCategoryId']", "$data['privateName'] === 'My name'\n                && $data['globalCategoryId']");p.write_text(s)
p=root/'tests/Integration/Platform/PlatformAccessWorkflowTest.php';s=p.read_text();old="""        $this->problem(
            422,
            fn() => $operator->updateProduct($admin, $home['id'], $created['id'], [
                'privateName' => 'Overwrite catalog name',
                'reason' => 'Invalid global identity edit',
                'expectedRevision' => 1,
            ]),
        );""";new="""        $operator->updateProduct($admin, $home['id'], $created['id'], [
            'privateName' => 'My household beans',
            'reason' => 'Correct the household display name only',
            'expectedRevision' => 1,
        ]);
        self::assertSame(
            'My household beans',
            $this->db->fetchOne('SELECT private_name FROM home_products WHERE id = ?', [$created['id']]),
        );
        $this->problem(
            409,
            fn() => $operator->updateProduct($admin, $home['id'], $created['id'], [
                'privateName' => 'Stale household edit',
                'reason' => 'A stale revision must still be refused',
                'expectedRevision' => 1,
            ]),
        );"""
if old in s: p.write_text(s.replace(old,new))
else: assert new in s
