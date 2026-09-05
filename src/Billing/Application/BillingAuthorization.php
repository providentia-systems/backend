<?php

declare(strict_types=1);

namespace Providentia\Billing\Application;

use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;

final readonly class BillingAuthorization
{
    public const BILLING_READ = HomePermission::BILLING_READ;
    public const BILLING_MANAGE = HomePermission::BILLING_MANAGE;
    public const BILLING_OPERATOR = 'billing_operator';
    public const PLATFORM_ADMINISTRATOR = 'platform_administrator';

    public function __construct(private HomeAuthorization $homes)
    {
    }

    public function requireHomeRead(AuthenticatedIdentity $identity, string $homeId): void
    {
        $this->homes->requirePermission($identity, $homeId, HomePermission::BILLING_READ);
    }

    public function requireHomeManage(AuthenticatedIdentity $identity, string $homeId): void
    {
        $this->homes->requirePermission($identity, $homeId, HomePermission::BILLING_MANAGE);
    }

    public function requireOperator(AuthenticatedIdentity $identity): void
    {
        if (
            array_intersect(
                ['billing.manage'],
                $identity->administratorPermissions,
            ) === []
        ) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
    }
}
