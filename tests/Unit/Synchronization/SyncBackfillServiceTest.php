<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Synchronization\Application\SyncBackfillRecord;
use Providentia\Synchronization\Application\SyncBackfillService;
use Providentia\Synchronization\Application\SyncBackfillStore;

final class SyncBackfillServiceTest extends TestCase
{
    public function testBatchIsBoundedAndRechecksMissingStateInsideTheTransaction(): void
    {
        $homeId = '01912345-6789-7abc-8def-0123456789ab';
        $actorId = '01912345-6789-7abc-9def-0123456789ab';
        $records = [
            $this->record($homeId, 'inventory-location', '01912345-6789-7abc-adef-0123456789ab'),
            $this->record($homeId, 'purchasing-store', '01912345-6789-7abc-bdef-0123456789ab'),
            $this->record($homeId, 'shopping-list', '01912345-6789-7abc-8def-1123456789ab'),
        ];
        $store = $this->createMock(SyncBackfillStore::class);
        $store->expects(self::once())->method('missingRecords')->with($homeId, 3)->willReturn($records);
        $store->expects(self::exactly(2))
            ->method('hasChange')
            ->willReturnOnConsecutiveCalls(false, true);
        $store->expects(self::once())->method('fallbackActor')->with($homeId)->willReturn($actorId);
        $writer = $this->createMock(ChangeFeedWriter::class);
        $writer->expects(self::once())
            ->method('put')
            ->with(
                $homeId,
                $actorId,
                'inventory-location',
                '01912345-6789-7abc-adef-0123456789ab',
                1,
                ['name' => 'Pantry'],
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(1);
        $service = new SyncBackfillService(
            $store,
            $writer,
            new BackfillImmediateTransactionManager(),
        );

        $result = $service->run($homeId, 2);

        self::assertSame(2, $result['scanned']);
        self::assertSame(1, $result['appended']);
        self::assertTrue($result['hasMore']);
        self::assertSame(['inventory-location' => 1], $result['byType']);
    }

    private function record(string $homeId, string $type, string $id): SyncBackfillRecord
    {
        return new SyncBackfillRecord(
            $homeId,
            $type,
            $id,
            1,
            ['name' => 'Pantry'],
            null,
            new DateTimeImmutable('2026-08-04T12:00:00+00:00'),
        );
    }
}

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- focused test double belongs with this unit.
final class BackfillImmediateTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
