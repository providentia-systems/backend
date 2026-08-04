<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

/**
 * Validated synchronization batch metadata.
 */
final readonly class SyncEnvelope
{
    /** @param list<mixed> $operations */
    public function __construct(
        public string $batchId,
        public string $deviceId,
        public ?string $lastPulledCursor,
        public array $operations,
        public int $protocolVersion = 1,
    ) {
    }
}
