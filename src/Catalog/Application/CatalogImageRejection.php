<?php

declare(strict_types=1);

namespace Providentia\Catalog\Application;

final class CatalogImageRejection extends \RuntimeException
{
    public function __construct(public readonly int $status, string $safeDetail)
    {
        parent::__construct($safeDetail);
    }
}
