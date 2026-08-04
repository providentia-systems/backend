<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\SharedKernel\Application\Problem;

final class SyncEnvelopeValidator
{
    public function __construct(private readonly int $maxBatchOperations)
    {
        if ($this->maxBatchOperations < 1) {
            throw new \InvalidArgumentException('maxBatchOperations must be positive.');
        }
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function validate(
        string $authenticatedDeviceId,
        string $idempotencyKey,
        array $envelope,
    ): SyncEnvelope {
        $this->rejectUnknownKeys($envelope);
        $protocolVersion = $envelope['protocolVersion'] ?? null;
        if (! in_array($protocolVersion, [1, 2], true)) {
            throw new Problem(422, 'Unsupported protocol', 'protocolVersion must be integer 1 or 2.');
        }
        if (($envelope['deviceId'] ?? null) !== $authenticatedDeviceId) {
            throw new Problem(
                403,
                'Device mismatch',
                'The synchronization device does not match the session.',
            );
        }

        $batchId = $this->uuid((string) ($envelope['batchId'] ?? ''), 'batchId');
        if (! hash_equals($batchId, strtolower(trim($idempotencyKey)))) {
            throw new Problem(
                422,
                'Invalid idempotency key',
                'Idempotency-Key must equal batchId so an identical batch retry has one stable identity.',
            );
        }

        $lastPulledCursor = $envelope['lastPulledCursor'] ?? null;
        if ($lastPulledCursor !== null && ! is_string($lastPulledCursor)) {
            throw new Problem(
                422,
                'Invalid cursor',
                'lastPulledCursor must be an opaque string or null.',
            );
        }

        $operations = $envelope['operations'] ?? null;
        if (
            ! is_array($operations)
            || ! array_is_list($operations)
            || count($operations) < 1
            || count($operations) > $this->maxBatchOperations
        ) {
            throw new Problem(
                422,
                'Invalid batch',
                'The batch operation count is outside the supported bounds.',
            );
        }

        return new SyncEnvelope(
            $batchId,
            $authenticatedDeviceId,
            $lastPulledCursor,
            $operations,
            $protocolVersion,
        );
    }

    /** @param array<string, mixed> $envelope */
    private function rejectUnknownKeys(array $envelope): void
    {
        $unknown = array_values(array_diff(
            array_keys($envelope),
            ['protocolVersion', 'batchId', 'deviceId', 'lastPulledCursor', 'operations'],
        ));
        if ($unknown === []) {
            return;
        }
        sort($unknown);

        throw new Problem(
            422,
            'Invalid request',
            sprintf(
                'Synchronization envelope contains unknown fields: %s.',
                implode(', ', $unknown),
            ),
        );
    }

    private function uuid(string $value, string $field): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new Problem(422, 'Invalid identifier', $field . ' must be a UUID.');
        }

        return strtolower($value);
    }
}
