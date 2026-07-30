<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Identity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Providentia\Identity\Application\AuthenticationRateLimiter;
use Providentia\Identity\Application\AuthenticationRateLimitStore;
use Providentia\SharedKernel\Application\Problem;

final class AuthenticationRateLimiterTest extends TestCase
{
    public function testIpAndNormalizedEmailIpBucketsAreConsumed(): void
    {
        $pepper = str_repeat('p', 32);
        $actualBuckets = [];
        $store = $this->createMock(AuthenticationRateLimitStore::class);
        $store->expects(self::exactly(2))
            ->method('consume')
            ->willReturnCallback(function (
                string $bucket,
                DateTimeImmutable $at,
                int $window,
                int $limit,
                int $block,
            ) use (&$actualBuckets): bool {
                $actualBuckets[] = $bucket;
                self::assertSame('2026-07-30T12:00:00+00:00', $at->format(DATE_ATOM));
                self::assertSame([900, 20, 900], [$window, $limit, $block]);

                return true;
            });
        $limiter = new AuthenticationRateLimiter(
            $store,
            new IdentityFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            $pepper,
        );

        $limiter->assertAllowed('192.0.2.10', '  User@Example.TEST ');

        self::assertSame([
            hash_hmac('sha256', 'ip:192.0.2.10', $pepper),
            hash_hmac('sha256', 'email-ip:user@example.test|192.0.2.10', $pepper),
        ], $actualBuckets);
    }

    public function testRejectedBucketProducesRateLimitProblem(): void
    {
        $store = $this->createStub(AuthenticationRateLimitStore::class);
        $store->method('consume')->willReturnOnConsecutiveCalls(true, false);
        $limiter = new AuthenticationRateLimiter(
            $store,
            new IdentityFixedClock(new DateTimeImmutable('2026-07-30T12:00:00+00:00')),
            str_repeat('p', 32),
        );

        try {
            $limiter->assertAllowed('192.0.2.10', 'user@example.test');
            self::fail('A rejected rate-limit bucket was ignored.');
        } catch (Problem $problem) {
            self::assertSame(429, $problem->status);
            self::assertSame('Too many authentication attempts', $problem->title);
        }
    }
}
