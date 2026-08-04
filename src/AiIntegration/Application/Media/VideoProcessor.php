<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application\Media;

interface VideoProcessor
{
    /**
     * @return array{durationMs: int, frames: list<array{offsetMs: int, mimeType: string, bytes: string}>}
     */
    public function extractFrames(string $bytes): array;
}
