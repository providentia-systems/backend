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
            sodium_memzero($buffer);
            $buffer = '';

            return;
        }

        // The production image includes sodium. This fallback still releases
        // the owned value deterministically in reduced development runtimes.
        $buffer = str_repeat("\0", strlen($buffer));
        $buffer = '';
    }
}
