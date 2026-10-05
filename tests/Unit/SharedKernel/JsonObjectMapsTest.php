<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Http\JsonObjectMaps;

final class JsonObjectMapsTest extends TestCase
{
    public function testOnlySelectedMapsBecomeObjectsWithoutChangingApplicationArrays(): void
    {
        $input = [
            'features' => ['inventory.read' => true, 'inventory.write' => false],
            'limits' => ['products.total' => -1, 'categories.total' => 0],
            'rolePermissions' => ['member' => ['inventory.read'], 'viewer' => []],
            'delegablePermissions' => ['inventory.read'],
            'data' => [],
        ];
        $result = JsonObjectMaps::serialize($input, ['features', 'limits', 'rolePermissions']);
        foreach (['features', 'limits', 'rolePermissions'] as $field) {
            self::assertInstanceOf(\stdClass::class, $result[$field]);
            self::assertSame($input[$field], (array) $result[$field]);
        }
        self::assertSame(['inventory.read'], $result['delegablePermissions']);
        self::assertSame([], $result['data']);
        self::assertSame($result, JsonObjectMaps::serialize($result, ['features', 'limits', 'rolePermissions']));
    }

    public function testEmptyMapsEncodeAsObjectsAndUnselectedListsStayArrays(): void
    {
        $result = JsonObjectMaps::serialize([
            'features' => [], 'limits' => [], 'rolePermissions' => [], 'delegablePermissions' => [],
        ], ['features', 'limits', 'rolePermissions']);

        self::assertSame(
            '{"features":{},"limits":{},"rolePermissions":{},"delegablePermissions":[]}',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        self::assertSame(['changed' => true], JsonObjectMaps::serialize(['changed' => true], ['features']));
    }
}
