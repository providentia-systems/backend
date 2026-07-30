<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use Providentia\SharedKernel\Application\Problem;

/**
 * Transport-specific problem raised by HTTP middleware and handlers.
 *
 * Application services raise {@see Problem} directly so they remain
 * independent of the HTTP adapter.
 */
final class HttpProblem extends Problem
{
}
