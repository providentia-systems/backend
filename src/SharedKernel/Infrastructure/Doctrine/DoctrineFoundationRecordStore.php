<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Providentia\SharedKernel\Application\FoundationRecordStore;
use Providentia\SharedKernel\Domain\FoundationRecord;

final class DoctrineFoundationRecordStore implements FoundationRecordStore
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function add(FoundationRecord $record): void
    {
        $this->entityManager->persist($record);
    }
}

