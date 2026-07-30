<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application;

use RuntimeException;

final class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        public readonly string $safeDetail,
    ) {
        parent::__construct($safeDetail);
    }
}
