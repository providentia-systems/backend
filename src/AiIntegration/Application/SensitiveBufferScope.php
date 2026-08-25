<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

/**
 * Tracks exact mutable variables owned by one transient operation. References
 * keep inner-scope copies reachable until eraseAll() runs in the outer finally.
 */
final class SensitiveBufferScope
{
    /** @var list<string> */
    private array $buffers = [];

    public function __construct(private readonly SensitiveBufferEraser $eraser)
    {
    }

    public function track(string &$buffer): void
    {
        $this->buffers[] = &$buffer;
    }

    public function eraseAll(): void
    {
        foreach ($this->buffers as &$buffer) {
            $this->eraser->erase($buffer);
        }
        unset($buffer);
        $this->buffers = [];
    }
}
