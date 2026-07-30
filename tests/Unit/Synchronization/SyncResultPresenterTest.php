<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Synchronization\Application\CursorCodec;
use Providentia\Synchronization\Application\SyncResultPresenter;

final class SyncResultPresenterTest extends TestCase
{
    private const HOME_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const ENTITY_ID = '01912345-6789-7abc-9def-0123456789ab';

    public function testAcceptedPutIsMappedWithoutPersistenceFields(): void
    {
        $result = $this->presenter()->applied(self::HOME_ID, [
            'operationId' => '01912345-6789-7abc-adef-0123456789ab',
            'status' => 'accepted',
            'entityType' => 'private-note',
            'entityId' => self::ENTITY_ID,
            'serverRevision' => 4,
            'cursor' => 8,
            'payload' => ['body' => 'freezer'],
            'deleted' => false,
        ]);

        $representation = $result['representation'] ?? null;
        if (! is_array($representation)) {
            self::fail('The accepted representation is missing.');
        }
        self::assertSame(4, $result['revision']);
        self::assertSame('freezer', $representation['body']);
        self::assertIsString($result['changeCursor']);
        self::assertArrayNotHasKey('serverRevision', $result);
        self::assertArrayNotHasKey('payload', $result);
    }

    public function testAcceptedDeleteAndConflictRemainUnambiguous(): void
    {
        $presenter = $this->presenter();
        $deleted = $presenter->applied(self::HOME_ID, [
            'status' => 'accepted',
            'entityId' => self::ENTITY_ID,
            'serverRevision' => 5,
            'cursor' => 9,
            'payload' => [],
            'deleted' => true,
        ]);

        $deletedRepresentation = $deleted['representation'] ?? null;
        if (! is_array($deletedRepresentation)) {
            self::fail('The deleted representation is missing.');
        }
        self::assertTrue($deletedRepresentation['deleted']);

        $conflict = ['status' => 'conflict', 'code' => 'revision_mismatch'];
        self::assertSame($conflict, $presenter->applied(self::HOME_ID, $conflict));
    }

    public function testChangeFeedMapsUpsertsAndTombstones(): void
    {
        $presenter = $this->presenter();
        $base = [
            'cursor' => 7,
            'entityType' => 'private-note',
            'entityId' => self::ENTITY_ID,
            'revision' => 3,
            'payloadSchemaVersion' => 1,
            'changedAt' => '2026-07-30 12:00:00',
        ];
        $upsert = $presenter->change(
            self::HOME_ID,
            10,
            [...$base, 'operationType' => 'put', 'payload' => ['body' => 'value']],
        );
        $delete = $presenter->change(
            self::HOME_ID,
            10,
            [...$base, 'operationType' => 'delete', 'payload' => []],
        );

        $upsertRepresentation = $upsert['representation'] ?? null;
        $tombstone = $delete['tombstone'] ?? null;
        if (! is_array($upsertRepresentation) || ! is_array($tombstone)) {
            self::fail('The change presentation is incomplete.');
        }
        self::assertSame('upsert', $upsert['operation']);
        self::assertSame('value', $upsertRepresentation['body']);
        self::assertSame('delete', $delete['operation']);
        self::assertSame('2026-07-30 12:00:00', $tombstone['deletedAt']);
        self::assertArrayNotHasKey('representation', $delete);
    }

    private function presenter(): SyncResultPresenter
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));

        return new SyncResultPresenter(new CursorCodec(str_repeat('s', 32), $clock, 3600));
    }
}
