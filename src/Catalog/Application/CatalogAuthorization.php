<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Problem;

final class CatalogAuthorization
{
    public const PLATFORM_ADMINISTRATOR = 'platform_administrator';
    public const CURATOR = 'catalog_curator';
    public const REVIEWER = 'catalog_reviewer';

    public function requireReviewer(AuthenticatedIdentity $identity): void
    {
        $this->requireAny($identity, [
            self::PLATFORM_ADMINISTRATOR,
            self::CURATOR,
            self::REVIEWER,
        ]);
    }

    public function requireCurator(AuthenticatedIdentity $identity): void
    {
        $this->requireAny($identity, [
            self::PLATFORM_ADMINISTRATOR,
            self::CURATOR,
        ]);
    }

    /** @param list<string> $roles */
    private function requireAny(AuthenticatedIdentity $identity, array $roles): void
    {
        if (array_intersect($roles, $identity->platformRoles) === []) {
            throw new Problem(404, 'Not found', 'The requested resource is unavailable.');
        }
    }
}
