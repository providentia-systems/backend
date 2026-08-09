<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use Providentia\SharedKernel\Application\TransactionManager;

final class IdentityTransactionManager implements TransactionManager
{
    public int $invocations = 0;
    public bool $active = false;

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
