<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

/** Privacy-safe subscription summary; amounts and provider references are excluded. */
interface OperatorSubscriptionReader
{
    /**
     * @param list<string> $homeIds
     * @return array<string, array{status: string, planCode: ?string,
     *     billingCycle: ?string, currentPeriodEnd: ?string}>
     */
    public function operatorSubscriptions(array $homeIds): array;
}
