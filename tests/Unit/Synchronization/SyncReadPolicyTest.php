<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomePermission;
use Providentia\Synchronization\Application\SyncReadPolicy;

final class SyncReadPolicyTest extends TestCase
{
    public function testEveryClassifiedEntityRequiresItsExactReadPermission(): void
    {
        foreach (SyncReadPolicy::ENTITY_PERMISSIONS as $entity => $permission) {
            self::assertTrue(SyncReadPolicy::canRead($entity, [$permission]), $entity);
            self::assertFalse(SyncReadPolicy::canRead($entity, []), $entity);
            self::assertFalse(SyncReadPolicy::canRead($entity, ['platform.admin']), $entity);
        }
        self::assertFalse(SyncReadPolicy::canRead('inventory-future-private-record', HomePermission::all()));
        self::assertFalse(SyncReadPolicy::canRead(null, HomePermission::all()));
        self::assertFalse(SyncReadPolicy::canRead('purchasing-receipt', [HomePermission::INVENTORY_READ]));
    }

    public function testMixedPagesPreserveOnlyPermittedRecordsInOriginalOrder(): void
    {
        $first = ['entityType' => 'inventory-location', 'entityId' => 'first'];
        $last = ['entityType' => 'inventory-home-product', 'entityId' => 'last'];
        $rows = [
            ['entityType' => 'purchasing-receipt', 'payload' => ['private' => 'receipt']],
            $first,
            ['entityType' => 'shopping-list', 'payload' => ['private' => 'list']],
            ['entityType' => 'inventory-unclassified', 'payload' => ['private' => 'unknown']],
            ['entityType' => null, 'payload' => ['private' => 'missing type']],
            ['entityType' => 123, 'payload' => ['private' => 'invalid type']],
            $last,
        ];
        self::assertSame([$first, $last], SyncReadPolicy::filter($rows, [HomePermission::INVENTORY_READ]));
        self::assertSame([], SyncReadPolicy::filter($rows, []));
    }

    public function testAcceptedReceiptIsNotChangedByResponseRedaction(): void
    {
        $stored = [
            'operationId' => 'immutable-operation',
            'status' => 'accepted',
            'commandType' => 'purchasing.receipt.commit',
            'entityId' => 'private-receipt',
            'result' => ['notes' => 'private note', 'price' => 100],
            'detail' => 'private diagnostic',
        ];
        $original = $stored;
        self::assertSame(
            ['operationId' => 'immutable-operation', 'status' => 'accepted'],
            SyncReadPolicy::result($stored, [HomePermission::INVENTORY_READ]),
        );
        self::assertSame($original, $stored);
        self::assertSame($stored, SyncReadPolicy::result($stored, [HomePermission::PURCHASES_READ]));
    }

    public function testUnclassifiedReceiptsAndFutureCommandsFailClosedEvenForAnOwner(): void
    {
        $receipt = ['operationId' => 'operation', 'status' => 'accepted', 'entityId' => 'private'];
        $safe = ['operationId' => 'operation', 'status' => 'accepted'];
        self::assertSame($safe, SyncReadPolicy::result($receipt, HomePermission::all()));
        $receipt['commandType'] = 'inventory.future.read-secrets';
        self::assertSame($safe, SyncReadPolicy::result($receipt, HomePermission::all()));
        self::assertNull(SyncReadPolicy::entityForCommand('inventory.future.read-secrets'));
        self::assertSame('inventory-location', SyncReadPolicy::entityForCommand('inventory.location.create'));
    }

    public function testDeniedConflictResponseContainsNoPrivateRepresentationOrDetail(): void
    {
        $receipt = [
            'operationId' => 'operation',
            'status' => 'conflict',
            'entityType' => 'private-note',
            'representation' => ['body' => 'private note'],
            'detail' => 'private note conflict',
            'remotePayload' => ['body' => 'another private note'],
        ];
        self::assertSame(
            ['operationId' => 'operation', 'status' => 'conflict'],
            SyncReadPolicy::result($receipt, []),
        );
        self::assertSame($receipt, SyncReadPolicy::result($receipt, [HomePermission::HOME_READ]));
        self::assertSame(
            $receipt,
            SyncReadPolicy::result($receipt, [HomePermission::INVENTORY_READ], 'inventory-location'),
        );
    }
}
