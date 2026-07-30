<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use Providentia\SharedKernel\Application\TransactionManager;

final class RecordingSharedKernelTransactionManager implements TransactionManager
{
    public bool $active = false;
    public int $invocations = 0;

    public function transactional(callable $operation): mixed
    {
        $this->invocations++;
        $this->active = true;

        try {
            return $operation();
        } finally {
            $this->active = false;
        }
    }
}
