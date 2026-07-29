<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use RuntimeException;

final class HttpProblem extends RuntimeException
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
