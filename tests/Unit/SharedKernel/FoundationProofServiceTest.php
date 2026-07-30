<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\Async\OutboxStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\FoundationProofService;
use Providentia\SharedKernel\Application\FoundationRecordStore;
use Providentia\SharedKernel\Application\UuidGenerator;
use Providentia\SharedKernel\Domain\FoundationRecord;

final class FoundationProofServiceTest extends TestCase
{
    public function testRecordAndOutboxMessageUseInjectedDependenciesInsideTransaction(): void
    {
        $transactions = new RecordingSharedKernelTransactionManager();
        $records = $this->createMock(FoundationRecordStore::class);
        $records->expects(self::once())
            ->method('add')
            ->with(self::callback(function (FoundationRecord $record) use ($transactions): bool {
                self::assertTrue($transactions->active);
                self::assertSame('01912345-6789-7abc-8def-0123456789ab', $record->id());
                self::assertSame('phase-proof', $record->label());
                self::assertSame('2026-07-30T12:00:00+00:00', $record->createdAt()->format(DATE_ATOM));

                return true;
            }));
        $outbox = $this->createMock(OutboxStore::class);
        $outbox->expects(self::once())
            ->method('append')
            ->with(self::callback(function (AsyncMessage $message) use ($transactions): bool {
                self::assertTrue($transactions->active);
                self::assertSame('01912345-6789-7abc-9def-0123456789ab', $message->id);
                self::assertSame('foundation.recorded.v1', $message->type);
                self::assertSame(
                    '01912345-6789-7abc-8def-0123456789ab',
                    $message->payload['recordId'],
                );

                return true;
            }));
        $ids = $this->createStub(UuidGenerator::class);
        $ids->method('generate')->willReturnOnConsecutiveCalls(
            '01912345-6789-7abc-8def-0123456789ab',
            '01912345-6789-7abc-9def-0123456789ab',
        );
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-07-30T12:00:00+00:00'));
        $service = new FoundationProofService($records, $transactions, $outbox, $clock, $ids);

        $result = $service->prove('phase-proof');

        self::assertSame('01912345-6789-7abc-8def-0123456789ab', $result);
        self::assertSame(1, $transactions->invocations);
        self::assertFalse($transactions->active);
    }
}
