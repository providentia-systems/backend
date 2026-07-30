<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Domain;

final readonly class ExtractionRequest
{
    public const PROMPT_TEMPLATE_VERSION = 1;

    public function __construct(
        public string $kind,
        public string $mimeType,
        public string $bytes,
        public string $model,
        public ?string $credential,
    ) {
    }
}
