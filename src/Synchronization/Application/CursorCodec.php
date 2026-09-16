<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use DateInterval;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;

final class CursorCodec
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

    public function encode(string $homeId, int $position, int $highWater, ?string $scope = null): string
    {
        $now = $this->clock->now();
        $payload = json_encode([
            'v' => 1,
            'home' => $homeId,
            'scope' => $scope,
            'position' => $position,
            'highWater' => $highWater,
            'expiresAt' => $now->add(new DateInterval('PT' . $this->ttlSeconds . 'S'))->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
        $encoded = $this->base64Url($payload);

        return $encoded . '.' . $this->base64Url(hash_hmac('sha256', $encoded, $this->secret, true));
    }

    /** @return array{position: int, highWater: int} */
    public function decode(string $cursor, string $homeId, ?string $scope = null): array
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2) {
            throw new Problem(422, 'Invalid cursor', 'The synchronization cursor is malformed.');
        }
        [$encoded, $signature] = $parts;
        $expected = $this->base64Url(hash_hmac('sha256', $encoded, $this->secret, true));
        if (! hash_equals($expected, $signature)) {
            throw new Problem(422, 'Invalid cursor', 'The synchronization cursor is invalid.');
        }
        $padding = str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode(strtr($encoded . $padding, '-_', '+/'), true);
        if ($decoded === false) {
            throw new Problem(422, 'Invalid cursor', 'The synchronization cursor is invalid.');
        }
        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Problem(422, 'Invalid cursor', 'The synchronization cursor is invalid.');
        }
        if (! is_array($payload)) {
            throw new Problem(422, 'Invalid cursor', 'The synchronization cursor is invalid.');
        }
        if (($payload['v'] ?? null) !== 1 || ($payload['home'] ?? null) !== $homeId) {
            throw new Problem(404, 'Not found', 'The requested synchronization state is unavailable.');
        }
        if ($scope !== null && (! is_string($payload['scope'] ?? null) || ! hash_equals($scope, $payload['scope']))) {
            throw new Problem(
                410,
                'Synchronization scope changed',
                'Bootstrap the currently authorized data without discarding pending operations.',
                'https://providentia.invalid/problems/sync_resync_required',
            );
        }
        if (! is_int($payload['expiresAt'] ?? null)) {
            throw new Problem(422, 'Invalid cursor', 'The cursor expiry is invalid.');
        }
        if ($payload['expiresAt'] <= $this->clock->now()->getTimestamp()) {
            throw new Problem(
                410,
                'Cursor expired',
                'The synchronization cursor expired. Perform a safe full resynchronization.',
                'https://providentia.invalid/problems/sync_resync_required',
            );
        }
        $position = $payload['position'] ?? null;
        $highWater = $payload['highWater'] ?? null;
        if (! is_int($position) || ! is_int($highWater) || $position < 0 || $highWater < $position) {
            throw new Problem(422, 'Invalid cursor', 'The synchronization cursor position is invalid.');
        }

        return ['position' => $position, 'highWater' => $highWater];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
