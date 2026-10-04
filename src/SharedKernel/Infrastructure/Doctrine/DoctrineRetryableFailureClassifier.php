<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\RetryableException;
use Providentia\SharedKernel\Application\RetryableFailureClassifier;
use Throwable;

final class DoctrineRetryableFailureClassifier implements RetryableFailureClassifier
{
    public function isRetryable(Throwable $error): bool
    {
        return $error instanceof RetryableException;
    }
}
