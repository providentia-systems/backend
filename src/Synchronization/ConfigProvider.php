<?php

declare(strict_types=1);

namespace Providentia\Synchronization;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['dependencies' => []];
    }
}

