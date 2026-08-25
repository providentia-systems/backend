<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use Providentia\AiIntegration\Application\SensitiveBufferEraser;

final class RecordingSensitiveBufferEraser implements SensitiveBufferEraser
{
    /** @var list<string> */
    public array $erased = [];

    public function erase(string &$buffer): void
    {
        if ($buffer !== '') {
            $this->erased[] = $buffer;
        }
        $buffer = '';
    }
}
