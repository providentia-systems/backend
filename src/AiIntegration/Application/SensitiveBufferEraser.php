<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

/**
 * Best-effort erasure boundary for short-lived secrets and direct-upload
 * media. PHP may create engine or extension-owned copies, so implementations
 * can only guarantee that the exact mutable variable passed here is released.
 */
interface SensitiveBufferEraser
{
    public function erase(string &$buffer): void;
}
