<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Providentia\Home\Application\HomeStore;
use Providentia\Inventory\Application\HomeProductIdentityReconciler;
use Providentia\Inventory\Infrastructure\Doctrine\DbalHomeProductIdentityRepairStore;
use Providentia\Inventory\Infrastructure\Doctrine\DbalHomeProductSyncNormalizer;
use Providentia\SharedKernel\Application\ChangeFeedWriter;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalChangeFeedWriter;
use Providentia\Synchronization\Infrastructure\Doctrine\DbalSyncStore;

final readonly class IdentityRepairTransactionManager implements TransactionManager
{
    public function __construct(private Connection $db)
    {
    }
    public function transactional(callable $operation): mixed
    {
        return $this->db->transactional(static fn (): mixed => $operation());
    }
}
