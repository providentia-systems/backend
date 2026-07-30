<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application;

use RuntimeException;

/**
 * Describes an expected application failure without depending on HTTP
 * middleware or a particular transport.
 */
class Problem extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        string $detail,
        public readonly string $type = 'about:blank',
    ) {
        parent::__construct($detail);
    }
}
