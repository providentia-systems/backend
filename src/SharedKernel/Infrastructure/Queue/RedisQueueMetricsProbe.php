<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Queue;

use Providentia\SharedKernel\Application\Async\QueueMetricsProbe;
use Redis;
use Throwable;

final class RedisQueueMetricsProbe implements QueueMetricsProbe
{
    public function __construct(
        private readonly string $dsn,
        private readonly string $queueName,
    ) {
    }

    public function measure(): array
    {
        try {
            $parts = parse_url($this->dsn);
            if ($parts === false || ! isset($parts['host'])) {
                throw new \InvalidArgumentException('Queue DSN is invalid.');
            }

            $redis = new Redis();
            $redis->connect($parts['host'], (int) ($parts['port'] ?? 6379), 1.0);
            if (isset($parts['pass'])) {
                $credentials = isset($parts['user'])
                    ? [$parts['user'], $parts['pass']]
                    : $parts['pass'];
                $redis->auth($credentials);
            }
            if (isset($parts['path']) && $parts['path'] !== '/') {
                $redis->select((int) ltrim($parts['path'], '/'));
            }
            $depth = (int) $redis->lLen($this->queueName);
            $redis->close();

            return ['up' => 1, 'depth' => $depth];
        } catch (Throwable) {
            return ['up' => 0, 'depth' => -1];
        }
    }
}
