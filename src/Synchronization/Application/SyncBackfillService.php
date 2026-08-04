<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\TransactionManager;

final readonly class SyncBackfillService
{
    public function __construct(
        private SyncBackfillStore $store,
        private ChangeFeedWriter $writer,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * @return array{scanned: int, appended: int, hasMore: bool, byType: array<string, int>}
     */
    public function run(?string $homeId, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Backfill limit must be between 1 and 1000.');
        }
        $records = $this->store->missingRecords($homeId, $limit + 1);
        $hasMore = count($records) > $limit;
        if ($hasMore) {
            array_pop($records);
        }
        $appended = 0;
        $byType = [];
        foreach ($records as $record) {
            $written = $this->transactions->transactional(function () use ($record): bool {
                if ($this->store->hasChange($record->homeId, $record->entityType, $record->entityId)) {
                    return false;
                }
                $actor = $record->actorUserId ?? $this->store->fallbackActor($record->homeId);
                $this->writer->put(
                    $record->homeId,
                    $actor,
                    $record->entityType,
                    $record->entityId,
                    $record->revision,
                    $record->representation,
                    $record->changedAt,
                );

                return true;
            });
            if (! $written) {
                continue;
            }
            $appended++;
            $byType[$record->entityType] = ($byType[$record->entityType] ?? 0) + 1;
        }
        ksort($byType);

        return [
            'scanned' => count($records),
            'appended' => $appended,
            'hasMore' => $hasMore,
            'byType' => $byType,
        ];
    }
}
