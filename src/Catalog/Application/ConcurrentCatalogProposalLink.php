<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

/** @internal Signals a lost unique-link race so the enclosing transaction is rolled back. */
final class ConcurrentCatalogProposalLink extends \RuntimeException
{
}
