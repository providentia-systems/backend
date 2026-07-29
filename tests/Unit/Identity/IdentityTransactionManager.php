<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use Providentia\SharedKernel\Application\TransactionManager;

final class IdentityTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
