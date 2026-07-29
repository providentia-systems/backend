<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use DateInterval;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Http\HttpProblem;

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

    public function encode(string $homeId, int $position, int $highWater): string
    {
        $now = $this->clock->now();
        $payload = json_encode([
            'v' => 1,
            'home' => $homeId,
            'position' => $position,
            'highWater' => $highWater,
            'expiresAt' => $now->add(new DateInterval('PT' . $this->ttlSeconds . 'S'))->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
        $encoded = $this->base64Url($payload);

        return $encoded . '.' . $this->base64Url(hash_hmac('sha256', $encoded, $this->secret, true));
    }

    /** @return array{position: int, highWater: int} */
    public function decode(string $cursor, string $homeId): array
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2) {
            throw new HttpProblem(422, 'Invalid cursor', 'The synchronization cursor is malformed.');
        }
        [$encoded, $signature] = $parts;
        $expected = $this->base64Url(hash_hmac('sha256', $encoded, $this->secret, true));
        if (! hash_equals($expected, $signature)) {
            throw new HttpProblem(422, 'Invalid cursor', 'The synchronization cursor is invalid.');
        }
        $padding = str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode(strtr($encoded . $padding, '-_', '+/'), true);
        if ($decoded === false) {
            throw new HttpProblem(422, 'Invalid cursor', 'The synchronization cursor is invalid.');
        }
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpProblem(422, 'Invalid cursor', 'The synchronization cursor is invalid.');
        }
        if (($payload['v'] ?? null) !== 1 || ($payload['home'] ?? null) !== $homeId) {
            throw new HttpProblem(404, 'Not found', 'The requested synchronization state is unavailable.');
        }
        if ((int) ($payload['expiresAt'] ?? 0) <= $this->clock->now()->getTimestamp()) {
            throw new HttpProblem(
                410,
                'Cursor expired',
                'The synchronization cursor expired. Perform a safe full resynchronization.',
                'https://providentia.invalid/problems/sync_resync_required',
            );
        }
        $position = (int) ($payload['position'] ?? -1);
        $highWater = (int) ($payload['highWater'] ?? -1);
        if ($position < 0 || $highWater < $position) {
            throw new HttpProblem(422, 'Invalid cursor', 'The synchronization cursor position is invalid.');
        }

        return ['position' => $position, 'highWater' => $highWater];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
