<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Http\HttpProblem;

final class AuthenticationRateLimiter
{
    public function __construct(
        private readonly AuthenticationRateLimitStore $store,
        private readonly Clock $clock,
        private readonly string $pepper,
    ) {
    }

    public function assertAllowed(string $ipAddress, string $email): void
    {
        foreach (['ip:' . $ipAddress, 'email-ip:' . mb_strtolower(trim($email)) . '|' . $ipAddress] as $bucket) {
            if (! $this->store->consume(
                hash_hmac('sha256', $bucket, $this->pepper),
                $this->clock->now(),
                900,
                20,
                900,
            )) {
                throw new HttpProblem(
                    429,
                    'Too many authentication attempts',
                    'Wait before trying this authentication operation again.',
                );
            }
        }
    }
}
