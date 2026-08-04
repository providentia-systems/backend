<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use DateInterval;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;

final class SnapshotCursorCodec
{
    public function __construct(
        private readonly string $secret,
        private readonly Clock $clock,
        private readonly int $ttlSeconds,
    ) {
        if (strlen($this->secret) < 16) {
            throw new \RuntimeException('SYNC_CURSOR_SECRET must contain at least 16 characters.');
        }
    }

    public function encode(string $homeId, int $highWater, string $entityType, string $entityId): string
    {
        $payload = json_encode([
            'v' => 1,
            'kind' => 'snapshot',
            'home' => $homeId,
            'highWater' => $highWater,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'expiresAt' => $this->clock->now()
                ->add(new DateInterval('PT' . $this->ttlSeconds . 'S'))
                ->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
        $encoded = $this->base64Url($payload);

        return $encoded . '.' . $this->base64Url(hash_hmac('sha256', $encoded, $this->secret, true));
    }

    /** @return array{highWater: int, entityType: string, entityId: string} */
    public function decode(string $cursor, string $homeId): array
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2) {
            throw new Problem(422, 'Invalid cursor', 'The snapshot cursor is malformed.');
        }
        [$encoded, $signature] = $parts;
        $expected = $this->base64Url(hash_hmac('sha256', $encoded, $this->secret, true));
        if (! hash_equals($expected, $signature)) {
            throw new Problem(422, 'Invalid cursor', 'The snapshot cursor is invalid.');
        }
        $padding = str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode(strtr($encoded . $padding, '-_', '+/'), true);
        if ($decoded === false) {
            throw new Problem(422, 'Invalid cursor', 'The snapshot cursor is invalid.');
        }
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Problem(422, 'Invalid cursor', 'The snapshot cursor is invalid.');
        }
        if (
            ($payload['v'] ?? null) !== 1
            || ($payload['kind'] ?? null) !== 'snapshot'
            || ($payload['home'] ?? null) !== $homeId
        ) {
            throw new Problem(404, 'Not found', 'The requested synchronization state is unavailable.');
        }
        if ((int) ($payload['expiresAt'] ?? 0) <= $this->clock->now()->getTimestamp()) {
            throw new Problem(
                410,
                'Snapshot expired',
                'The snapshot expired. Perform a safe full resynchronization.',
                'https://providentia.invalid/problems/sync_resync_required',
            );
        }
        $highWater = (int) ($payload['highWater'] ?? -1);
        $entityType = $payload['entityType'] ?? null;
        $entityId = $payload['entityId'] ?? null;
        if ($highWater < 0 || ! is_string($entityType) || $entityType === '' || ! is_string($entityId)) {
            throw new Problem(422, 'Invalid cursor', 'The snapshot cursor position is invalid.');
        }

        return ['highWater' => $highWater, 'entityType' => $entityType, 'entityId' => $entityId];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
