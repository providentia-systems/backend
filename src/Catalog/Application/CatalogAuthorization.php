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
        $this->requireAny($identity, ['catalog.review', 'catalog.curate']);
    }

    public function requireCurator(AuthenticatedIdentity $identity): void
    {
        $this->requireAny($identity, ['catalog.curate']);
    }

    /** @param list<string> $permissions */
    private function requireAny(AuthenticatedIdentity $identity, array $permissions): void
    {
        if (array_intersect($permissions, $identity->administratorPermissions) === []) {
            throw new Problem(403, 'Permission required', 'Your administrator group does not permit this catalog action.');
        }
    }
}
