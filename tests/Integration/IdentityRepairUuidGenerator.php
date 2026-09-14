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

final class IdentityRepairUuidGenerator implements \Providentia\SharedKernel\Application\UuidGenerator
{
    private int $sequence = 100;
    public function generate(): string
    {
        return sprintf('01912345-6789-7abc-8def-%012d', ++$this->sequence);
    }
}
