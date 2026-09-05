<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

/**
 * A closed, validated protocol-v2 pantry command.
 *
 * The client supplies the entity identifier for create commands so dependent
 * offline commands can refer to the same aggregate before the first push.
 */
final readonly class SyncCommand
{
    /**
     * @param array<string, mixed> $payload */
    public function __construct(
        public string $operationId,
        public string $commandType,
        public string $entityId,
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
            'commandType' => $this->commandType,
            'entityId' => $this->entityId,
            'baseRevision' => $this->baseRevision,
            'clientTimestamp' => $this->clientTimestamp,
            'payloadSchemaVersion' => $this->payloadSchemaVersion,
            'payload' => $this->payload,
        ];
    }
}
