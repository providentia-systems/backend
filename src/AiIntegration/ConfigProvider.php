<?php

declare(strict_types=1);

namespace Providentia\AiIntegration;

final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['dependencies' => []];
    }
}

