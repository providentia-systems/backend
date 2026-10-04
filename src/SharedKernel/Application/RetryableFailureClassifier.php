<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application;

use Throwable;

interface RetryableFailureClassifier
{
    public function isRetryable(Throwable $error): bool;
}
