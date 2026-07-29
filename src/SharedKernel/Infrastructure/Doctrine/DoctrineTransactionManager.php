<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Providentia\SharedKernel\Application\TransactionManager;

final class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction($operation);
    }
}
