<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use Providentia\Identity\Application\AuthenticatedIdentity;

interface CatalogHomeAccess
{
    public function requireRead(AuthenticatedIdentity $identity, string $homeId): void;

    public function requireContribution(AuthenticatedIdentity $identity, string $homeId): void;

    public function requireConsentManagement(AuthenticatedIdentity $identity, string $homeId): void;

    public function requireImport(AuthenticatedIdentity $identity, string $homeId): void;
}
