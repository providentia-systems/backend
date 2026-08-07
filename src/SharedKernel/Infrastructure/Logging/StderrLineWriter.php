<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Logging;

use Closure;

final class StderrLineWriter
{
    /** @var Closure(string): void */
    private readonly Closure $fallback;

    /** @param null|Closure(string): void $fallback */
    public function __construct(
        private readonly string $streamUri = 'php://stderr',
        ?Closure $fallback = null,
    ) {
        $this->fallback = $fallback ?? static function (string $line): void {
            @error_log(rtrim($line, "\r\n"));
        };
    }

    public function __invoke(string $line): void
    {
        $written = @file_put_contents($this->streamUri, $line, FILE_APPEND);
        if ($written === false) {
            ($this->fallback)($line);
        }
    }
}
