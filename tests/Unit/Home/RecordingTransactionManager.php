<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Home;

use PHPUnit\Framework\Assert;
use Providentia\SharedKernel\Application\TransactionManager;

final class RecordingTransactionManager implements TransactionManager
{
    public bool $active = false;
    public int $invocations = 0;

    public function transactional(callable $operation): mixed
    {
        Assert::assertFalse($this->active);
        $this->active = true;
        $this->invocations++;
        try {
            return $operation();
        } finally {
            $this->active = false;
        }
    }
}
