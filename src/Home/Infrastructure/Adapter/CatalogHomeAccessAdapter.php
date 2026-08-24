<?php

declare(strict_types=1);

namespace Providentia\Home\Infrastructure\Adapter;

use Providentia\Catalog\Application\CatalogHomeAccess;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\Home\Application\HomePermission;
use Providentia\Identity\Application\AuthenticatedIdentity;

final readonly class CatalogHomeAccessAdapter implements CatalogHomeAccess
{
    public function __construct(private HomeAuthorization $homes)
    {
    }

    public function requireRead(AuthenticatedIdentity $identity, string $homeId): void
    {
        $this->homes->requirePermission($identity, $homeId, HomePermission::HOME_READ);
    }

    public function requireContribution(AuthenticatedIdentity $identity, string $homeId): void
    {
        $this->homes->requirePermission($identity, $homeId, HomePermission::CATALOG_CONTRIBUTE);
    }

    public function requireConsentManagement(AuthenticatedIdentity $identity, string $homeId): void
    {
        $this->homes->requirePermission($identity, $homeId, HomePermission::CATALOG_CONSENT_MANAGE);
    }

    public function requireImport(AuthenticatedIdentity $identity, string $homeId): void
    {
        $this->homes->requirePermission($identity, $homeId, HomePermission::CATALOG_IMPORT);
    }
}
