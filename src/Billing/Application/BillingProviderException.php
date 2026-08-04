<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

final class BillingProviderException extends \RuntimeException
{
    public function __construct(public readonly string $safeCode, string $safeMessage)
    {
        parent::__construct($safeMessage);
    }
}
