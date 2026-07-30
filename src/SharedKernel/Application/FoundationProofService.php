<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application;

use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\Async\OutboxStore;
use Providentia\SharedKernel\Domain\FoundationRecord;

final class FoundationProofService
{
    public function __construct(
        private readonly FoundationRecordStore $records,
        private readonly TransactionManager $transactions,
        private readonly OutboxStore $outbox,
        private readonly Clock $clock,
        private readonly UuidGenerator $ids,
    ) {
    }

    /**
     * Persists an ORM aggregate and its event in one database transaction.
     * This historical Phase 1 proof remains CLI-only and outside product flows.
     */
    public function prove(string $label): string
    {
        $id = $this->ids->generate();
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use ($id, $label, $now): string {
            $this->records->add(new FoundationRecord($id, $label, $now));
            $this->outbox->append(new AsyncMessage(
                $this->ids->generate(),
                'foundation.recorded.v1',
                ['recordId' => $id, 'label' => $label],
                $now,
            ));
            return $id;
        });
    }
}
