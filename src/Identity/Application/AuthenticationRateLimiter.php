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

    public function assertCodeResendAllowed(string $email, string $purpose): void
    {
        if (! $this->store->consume(
            hash_hmac('sha256', 'code-resend:' . $purpose . ':' . $email, $this->pepper),
            $this->clock->now(),
            60,
            1,
            60,
        )) {
            throw new Problem(429, 'Please wait', 'Wait one minute before requesting another code.');
        }
    }

    public function assertCodeVerificationAllowed(string $ip): void
    {
        if (! $this->store->consume(
            hash_hmac('sha256', 'code-verify:' . $ip, $this->pepper),
            $this->clock->now(),
            900,
            60,
            900,
        )) {
            throw new Problem(429, 'Too many attempts', 'Wait before trying another verification code.');
        }
    }

    public function assertLoginLinkProofAllowed(string $ipAddress, ?string $requestId = null): void
    {
        $buckets = ['login-link-ip:' . $ipAddress => 3000];
        if ($requestId !== null) {
            // The middleware adds these buckets only after proving the request
            // exists, preventing random UUIDs from creating unbounded rows.
            $buckets['login-link-request:' . $requestId] = 600;
            $buckets['login-link-request-ip:' . $requestId . '|' . $ipAddress] = 600;
        }
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
                    'Too many login-link checks',
                    'Wait before checking this login request again.',
                );
            }
        }
    }
}
