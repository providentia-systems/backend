<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateInterval;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class EmailCodeService
{
    public function __construct(
        private readonly EmailCodeStore $store,
        private readonly CredentialHasher $hasher,
        private readonly NotificationOutbox $outbox,
        private readonly AuthenticationRateLimiter $limiter,
        private readonly UuidGenerator $ids,
        private readonly SecureTokenGenerator $tokens,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array{challengeId: string, bindingToken: string, expiresAt: string, resendAfterSeconds: int}
     */
    public function request(string $email, string $purpose, ?string $userId, array $context, string $ip): array
    {
        $email = self::normalizeEmail($email);
        $this->limiter->assertAllowed($ip, $email);
        $this->limiter->assertCodeResendAllowed($email, $purpose);
        $id = $this->ids->generate();
        $binding = $this->tokens->generate();
        $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $now = $this->clock->now();
        $expires = $now->add(new DateInterval('PT10M'));
        $this->transactions->transactional(function () use ($id, $email, $purpose, $userId, $context, $binding, $code, $now, $expires): void {
            $this->store->issue([
                'id' => $id,
                'email' => $email,
                'purpose' => $purpose,
                'user_id' => $userId,
                'code_hash' => $this->hasher->hashToken($id . ':' . $purpose . ':' . $code),
                'binding_hash' => $this->hasher->hashToken($binding),
                'context_json' => json_encode($context, JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expires->format('Y-m-d H:i:s'),
                'consumed_at' => null,
            ]);
            $this->outbox->enqueue($this->ids->generate(), 'email-code', $email, [
                'code' => $code,
                'purpose' => $purpose,
                'expiresAt' => $expires->format(DATE_ATOM),
            ], $now);
        });

        return ['challengeId' => $id, 'bindingToken' => $binding, 'expiresAt' => $expires->format(DATE_ATOM), 'resendAfterSeconds' => 60];
    }

    /** @return array<string, mixed> */
    public function verify(string $id, string $binding, string $code, string $purpose, string $ip): array
    {
        $this->limiter->assertCodeVerificationAllowed($ip);
        if (strlen($id) > 36 || strlen($binding) > 128 || preg_match('/^[0-9]{8}$/D', $code) !== 1) {
            throw new Problem(422, 'Invalid code', 'Enter the eight-digit code sent to your email.');
        }
        $challenge = $this->store->consume(
            $id,
            $this->hasher->hashToken($id . ':' . $purpose . ':' . $code),
            $this->hasher->hashToken($binding),
            $purpose,
            $this->clock->now()->format('Y-m-d H:i:s'),
        );
        if ($challenge === null) {
            throw new Problem(422, 'Invalid code', 'The code is incorrect, expired, already used, or has exhausted its attempts.');
        }
        $challenge['context'] = json_decode((string) $challenge['context_json'], true, 32, JSON_THROW_ON_ERROR);

        return $challenge;
    }

    public static function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new Problem(422, 'Invalid email', 'Enter a valid email address.');
        }

        return $email;
    }
}
