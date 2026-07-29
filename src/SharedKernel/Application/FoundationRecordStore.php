<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application;

use Providentia\SharedKernel\Domain\FoundationRecord;

interface FoundationRecordStore
{
    public function add(FoundationRecord $record): void;
}

