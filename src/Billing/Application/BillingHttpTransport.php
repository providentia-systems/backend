<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

interface BillingHttpTransport
{
    public function send(BillingHttpRequest $request): BillingHttpResponse;
}
