<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;

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
        $email = mb_strtolower(trim($email));
        $buckets = [
            'ip:' . $ipAddress => 20,
            'email:' . $email => 5,
            'email-ip:' . $email . '|' . $ipAddress => 10,
        ];
        foreach ($buckets as $bucket => $limit) {
            if (
                ! $this->store->consume(
                    hash_hmac('sha256', $bucket, $this->pepper),
                    $this->clock->now(),
                    900,
                    $limit,
                    900,
                )
            ) {
                throw new Problem(
                    429,
                    'Too many authentication attempts',
                    'Wait before trying this authentication operation again.',
                );
            }
        }
    }

    public function assertLoginLinkProofAllowed(string $ipAddress): void
    {
        // Do not persist a bucket derived from an unverified request ID: an
        // attacker could otherwise create unbounded rows with random UUIDs.
        $buckets = ['login-link-ip:' . $ipAddress => 3000];
        foreach ($buckets as $bucket => $limit) {
            if (! $this->store->consume(
                hash_hmac('sha256', $bucket, $this->pepper),
                $this->clock->now(),
                900,
                $limit,
                900,
            )) {
                throw new Problem(
                    429,
                    'Too many login-link checks',
                    'Wait before checking this login request again.',
                );
            }
        }
    }
}
