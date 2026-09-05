<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

/**
 * Closed, validated client operation accepted by the synchronization port.
 */
final readonly class SyncOperation
{
    /**
     * @param array<string, mixed> $payload */
    public function __construct(
        public string $operationId,
        public string $entityType,
        public string $entityId,
        public string $operationType,
        public ?int $baseRevision,
        public string $clientTimestamp,
        public int $payloadSchemaVersion,
        public array $payload,
    ) {
    }

    /**
     * @return array<string, mixed> */
    public function requestShape(): array
    {
        return [
            'operationId' => $this->operationId,
            'entityType' => $this->entityType,
            'entityId' => $this->entityId,
            'operationType' => $this->operationType,
            'baseRevision' => $this->baseRevision,
            'clientTimestamp' => $this->clientTimestamp,
            'payloadSchemaVersion' => $this->payloadSchemaVersion,
            'payload' => $this->payload,
        ];
    }
}
