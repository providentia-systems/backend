<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Security;

use Providentia\AiIntegration\Application\SensitiveBufferEraser;

final class SodiumSensitiveBufferEraser implements SensitiveBufferEraser
{
    public function erase(string &$buffer): void
    {
        if ($buffer === '') {
            return;
        }

        if (function_exists('sodium_memzero')) {
            // sodium_memzero() changes its argument to null. Keep that
            // extension-owned postcondition away from this method's string
            // reference contract while still erasing the exact owned bytes.
            $owned = $buffer;
            $buffer = '';
            sodium_memzero($owned);

            return;
        }

        // The production image includes sodium. This fallback still releases
        // the owned value deterministically in reduced development runtimes.
        $buffer = str_repeat("\0", strlen($buffer));
        $buffer = '';
    }
}
