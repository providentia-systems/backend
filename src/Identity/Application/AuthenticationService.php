<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateInterval;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class AuthenticationService
{
    public function __construct(
        private readonly IdentityStore $store,
        private readonly CredentialHasher $hasher,
        private readonly AccountNotificationSender $notifications,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly SecureTokenGenerator $tokens,
        private readonly int $accessTtlSeconds,
        private readonly int $refreshTtlSeconds,
        private readonly bool $passwordLoginEnabled = true,
    ) {
    }

    /** @return array{verificationToken: string|null} */
    public function register(
        string $email,
        string $password,
        string $displayName,
        string $locale = 'en-NA',
        string $timezone = 'Africa/Windhoek',
    ): array {
        $this->requirePasswordLogin();
        $normalizedEmail = $this->normalizeEmail($email);
        $this->assertPassword($password);
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 120) {
            throw new Problem(422, 'Validation failed', 'Display name must contain 1 to 120 characters.');
        }
        // Always perform the expensive hash so account-existence timing is less distinct.
        $passwordHash = $this->hasher->hashPassword($password);

        $result = $this->transactions->transactional(function () use (
            $normalizedEmail,
            $passwordHash,
            $displayName,
            $locale,
            $timezone,
        ): array {
            $existing = $this->store->findUserByEmail($normalizedEmail);
            if ($existing !== null && $existing['email_verified_at'] !== null) {
                return ['verificationToken' => null];
            }

            $now = $this->clock->now();
            $userId = $existing === null ? $this->ids->generate() : (string) $existing['id'];
            $token = $this->tokens->generate();
            if ($existing === null) {
                $this->store->createUser(
                    $userId,
                    $normalizedEmail,
                    $passwordHash,
                    $displayName,
                    $locale,
                    $timezone,
                    $now,
                );
            }
            $this->store->issueOneTimeToken(
                $this->ids->generate(),
                $userId,
                'verify-email',
                $this->hasher->hashToken($token),
                $now->add(new DateInterval('P1D')),
                $now,
            );
            $this->notifications->sendEmailVerification($normalizedEmail, $token);

            return ['verificationToken' => $token];
        });

        return $result;
    }

    public function verifyEmail(string $token): void
    {
        $this->transactions->transactional(function () use ($token): void {
            $now = $this->clock->now();
            $userId = $this->store->consumeOneTimeToken(
                'verify-email',
                $this->hasher->hashToken($token),
                $now,
            );
            if ($userId === null) {
                throw new Problem(422, 'Invalid token', 'The verification token is invalid or expired.');
            }
            $this->store->markEmailVerified($userId, $now);
        });
    }

    public function requestMagicLink(
        string $email,
        string $displayName = '',
        string $locale = 'en-NA',
        string $timezone = 'Africa/Windhoek',
    ): ?string {
        $normalizedEmail = $this->normalizeEmail($email);
        $displayName = trim($displayName);
        if ($displayName !== '' && mb_strlen($displayName) > 120) {
            throw new Problem(422, 'Validation failed', 'Display name must not exceed 120 characters.');
        }

        return $this->transactions->transactional(function () use (
            $normalizedEmail,
            $displayName,
            $locale,
            $timezone,
        ): ?string {
            $user = $this->store->findUserByEmail($normalizedEmail);
            $now = $this->clock->now();
            if ($user === null) {
                $userId = $this->ids->generate();
                $fallbackName = strstr($normalizedEmail, '@', true);
                $this->store->createUser(
                    $userId,
                    $normalizedEmail,
                    $this->hasher->hashPassword($this->tokens->generate()),
                    $displayName !== '' ? $displayName : (is_string($fallbackName) ? $fallbackName : 'Member'),
                    $locale,
                    $timezone,
                    $now,
                );
            } else {
                if ((string) $user['status'] !== 'active') {
                    return null;
                }
                $userId = (string) $user['id'];
            }
            $token = $this->tokens->generate();
            $this->store->issueOneTimeToken(
                $this->ids->generate(),
                $userId,
                'magic-sign-in',
                $this->hasher->hashToken($token),
                $now->add(new DateInterval('PT15M')),
                $now,
            );
            $this->notifications->sendMagicLink($normalizedEmail, $token);

            return $token;
        });
    }

    /**
     * @return array{
     *     accessToken: string,
     *     refreshToken: string,
     *     csrfToken: string,
     *     accessExpiresAt: string,
     *     sessionId: string,
     *     deviceId: string,
     *     userId: string
     * }
     */
    public function exchangeMagicLink(
        string $token,
        string $deviceId,
        string $deviceName,
        string $platform,
    ): array {
        return $this->transactions->transactional(function () use (
            $token,
            $deviceId,
            $deviceName,
            $platform,
        ): array {
            $now = $this->clock->now();
            $userId = $this->store->consumeOneTimeToken(
                'magic-sign-in',
                $this->hasher->hashToken($token),
                $now,
            );
            if ($userId === null) {
                throw new Problem(422, 'Invalid token', 'The sign-in link is invalid or expired.');
            }
            $user = $this->store->findUserById($userId);
            if ($user === null || (string) $user['status'] !== 'active') {
                throw new Problem(403, 'Account unavailable', 'This account is not active.');
            }
            if ($user['email_verified_at'] === null) {
                $this->store->markEmailVerified($userId, $now);
            }

            return $this->issueSession(
                $userId,
                $this->assertUuid($deviceId, 'deviceId'),
                trim($deviceName),
                trim($platform),
            );
        });
    }

    public function requestStepUp(AuthenticatedIdentity $identity, string $action): ?string
    {
        $purpose = $this->stepUpPurpose($action);

        return $this->transactions->transactional(function () use ($identity, $action, $purpose): ?string {
            $user = $this->store->findUserById($identity->userId);
            if ($user === null || (string) $user['status'] !== 'active') {
                return null;
            }
            $token = $this->tokens->generate();
            $now = $this->clock->now();
            $this->store->issueOneTimeToken(
                $this->ids->generate(),
                $identity->userId,
                $purpose,
                $this->hasher->hashToken($token),
                $now->add(new DateInterval('PT10M')),
                $now,
            );
            $this->notifications->sendStepUpLink((string) $user['email'], $token, $action);

            return $token;
        });
    }

    public function consumeStepUp(AuthenticatedIdentity $identity, string $token, string $action): void
    {
        $userId = $this->store->consumeOneTimeToken(
            $this->stepUpPurpose($action),
            $this->hasher->hashToken($token),
            $this->clock->now(),
        );
        if ($userId === null || ! hash_equals($identity->userId, $userId)) {
            throw new Problem(422, 'Invalid step-up proof', 'The confirmation link is invalid or expired.');
        }
    }

    /**
     * @return array{
     *     accessToken: string,
     *     refreshToken: string,
     *     csrfToken: string,
     *     accessExpiresAt: string,
     *     sessionId: string,
     *     deviceId: string,
     *     userId: string
     * }
     */
    public function login(
        string $email,
        string $password,
        string $deviceId,
        string $deviceName,
        string $platform,
    ): array {
        $this->requirePasswordLogin();
        $user = $this->store->findUserByEmail($this->normalizeEmail($email));
        if (
            $user !== null
            && $user['locked_until'] !== null
            && new \DateTimeImmutable((string) $user['locked_until']) > $this->clock->now()
        ) {
            throw new Problem(
                429,
                'Account temporarily locked',
                'Too many failed sign-in attempts. Try again later.',
            );
        }
        if (
            $user === null
            || ! $this->hasher->verifyPassword($password, (string) $user['password_hash'])
        ) {
            if ($user !== null) {
                $this->store->recordFailedLogin((string) $user['id'], $this->clock->now());
            }
            throw new Problem(401, 'Authentication failed', 'Invalid email or password.');
        }
        if ($user['email_verified_at'] === null) {
            throw new Problem(403, 'Email verification required', 'Verify the email address before signing in.');
        }
        if ((string) $user['status'] !== 'active') {
            throw new Problem(403, 'Account unavailable', 'This account is not active.');
        }

        $this->store->clearFailedLogin((string) $user['id']);

        return $this->issueSession(
            (string) $user['id'],
            $this->assertUuid($deviceId, 'deviceId'),
            trim($deviceName),
            trim($platform),
        );
    }

    /**
     * @return array{
     *     accessToken: string,
     *     refreshToken: string,
     *     csrfToken: string,
     *     accessExpiresAt: string,
     *     sessionId: string,
     *     deviceId: string,
     *     userId: string
     * }
     */
    public function refresh(string $refreshToken): array
    {
        $now = $this->clock->now();
        $session = $this->store->findSessionByRefreshHash(
            $this->hasher->hashToken($refreshToken),
            $now,
        );
        if ($session === null) {
            if ($this->store->revokeRefreshReplay($this->hasher->hashToken($refreshToken), $now)) {
                throw new Problem(
                    401,
                    'Credential replay detected',
                    'The device session was revoked because a rotated refresh credential was reused.',
                );
            }
            throw new Problem(401, 'Authentication failed', 'The refresh credential is invalid or expired.');
        }

        $accessToken = $this->tokens->generate();
        $nextRefreshToken = $this->tokens->generate();
        $csrfToken = $this->tokens->generate();
        $accessExpiry = $now->add(new DateInterval('PT' . $this->accessTtlSeconds . 'S'));
        $refreshExpiry = $now->add(new DateInterval('PT' . $this->refreshTtlSeconds . 'S'));
        $rotated = $this->store->rotateSession(
            (string) $session['id'],
            (string) $session['refresh_token_hash'],
            $this->hasher->hashToken($accessToken),
            $this->hasher->hashToken($nextRefreshToken),
            $this->hasher->hashToken($csrfToken),
            $accessExpiry,
            $refreshExpiry,
            $now,
        );
        if (! $rotated) {
            throw new Problem(
                401,
                'Credential replay detected',
                'A concurrent refresh was detected and the device session was revoked.',
            );
        }

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $nextRefreshToken,
            'csrfToken' => $csrfToken,
            'accessExpiresAt' => $accessExpiry->format(DATE_ATOM),
            'sessionId' => (string) $session['id'],
            'deviceId' => (string) $session['device_id'],
            'userId' => (string) $session['user_id'],
        ];
    }

    public function authenticate(string $accessToken): AuthenticatedIdentity
    {
        $session = $this->store->findSessionByAccessHash(
            $this->hasher->hashToken($accessToken),
            $this->clock->now(),
        );
        if ($session === null) {
            throw new Problem(401, 'Authentication required', 'A valid access credential is required.');
        }

        return new AuthenticatedIdentity(
            (string) $session['user_id'],
            (string) $session['id'],
            (string) $session['device_id'],
            $session['active_home_id'] === null ? null : (string) $session['active_home_id'],
            $this->store->platformRoles((string) $session['user_id']),
        );
    }

    /** @return list<array<string, mixed>> */
    public function listSessions(AuthenticatedIdentity $identity): array
    {
        return $this->store->listSessions($identity->userId);
    }

    public function revokeSession(AuthenticatedIdentity $identity, string $sessionId): void
    {
        if (! $this->store->revokeSession($identity->userId, $sessionId, $this->clock->now())) {
            throw new Problem(404, 'Not found', 'The requested session is unavailable.');
        }
    }

    public function verifyCsrf(AuthenticatedIdentity $identity, string $token): bool
    {
        return $token !== ''
            && $this->store->verifyCsrf($identity->sessionId, $this->hasher->hashToken($token));
    }

    public function requestPasswordReset(string $email): ?string
    {
        $this->requirePasswordLogin();
        $normalizedEmail = $this->normalizeEmail($email);

        return $this->transactions->transactional(function () use ($normalizedEmail): ?string {
            $user = $this->store->findUserByEmail($normalizedEmail);
            if ($user === null) {
                return null;
            }
            $token = $this->tokens->generate();
            $now = $this->clock->now();
            $this->store->issueOneTimeToken(
                $this->ids->generate(),
                (string) $user['id'],
                'password-reset',
                $this->hasher->hashToken($token),
                $now->add(new DateInterval('PT1H')),
                $now,
            );
            $this->notifications->sendPasswordReset((string) $user['email'], $token);

            return $token;
        });
    }

    public function resendVerification(string $email): ?string
    {
        $this->requirePasswordLogin();
        $normalizedEmail = $this->normalizeEmail($email);

        return $this->transactions->transactional(function () use ($normalizedEmail): ?string {
            $user = $this->store->findUserByEmail($normalizedEmail);
            if ($user === null || $user['email_verified_at'] !== null) {
                return null;
            }
            $token = $this->tokens->generate();
            $now = $this->clock->now();
            $this->store->issueOneTimeToken(
                $this->ids->generate(),
                (string) $user['id'],
                'verify-email',
                $this->hasher->hashToken($token),
                $now->add(new DateInterval('P1D')),
                $now,
            );
            $this->notifications->sendEmailVerification((string) $user['email'], $token);

            return $token;
        });
    }

    public function resetPassword(string $token, string $password): void
    {
        $this->requirePasswordLogin();
        $this->assertPassword($password);
        $this->transactions->transactional(function () use ($token, $password): void {
            $now = $this->clock->now();
            $userId = $this->store->consumeOneTimeToken(
                'password-reset',
                $this->hasher->hashToken($token),
                $now,
            );
            if ($userId === null) {
                throw new Problem(422, 'Invalid token', 'The reset token is invalid or expired.');
            }
            $this->store->changePassword($userId, $this->hasher->hashPassword($password), $now);
            $this->store->revokeAllSessions($userId, $now);
        });
    }

    /**
     * @return array{
     *     accessToken: string,
     *     refreshToken: string,
     *     csrfToken: string,
     *     accessExpiresAt: string,
     *     sessionId: string,
     *     deviceId: string,
     *     userId: string
     * }
     */
    private function issueSession(string $userId, string $deviceId, string $deviceName, string $platform): array
    {
        $now = $this->clock->now();
        $accessToken = $this->tokens->generate();
        $refreshToken = $this->tokens->generate();
        $accessExpiry = $now->add(new DateInterval('PT' . $this->accessTtlSeconds . 'S'));
        $refreshExpiry = $now->add(new DateInterval('PT' . $this->refreshTtlSeconds . 'S'));
        $sessionId = $this->ids->generate();
        $csrfToken = $this->tokens->generate();
        $this->store->createSession(
            $sessionId,
            $userId,
            $deviceId,
            $deviceName === '' ? 'Unnamed device' : mb_substr($deviceName, 0, 120),
            $platform === '' ? 'unknown' : mb_substr($platform, 0, 40),
            $this->hasher->hashToken($accessToken),
            $this->hasher->hashToken($refreshToken),
            $this->hasher->hashToken($csrfToken),
            $accessExpiry,
            $refreshExpiry,
            $now,
        );

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'csrfToken' => $csrfToken,
            'accessExpiresAt' => $accessExpiry->format(DATE_ATOM),
            'sessionId' => $sessionId,
            'deviceId' => $deviceId,
            'userId' => $userId,
        ];
    }

    private function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new Problem(422, 'Validation failed', 'A valid email address is required.');
        }

        return $email;
    }

    private function assertPassword(string $password): void
    {
        if (
            strlen($password) < 12
            || strlen($password) > 1024
            || preg_match('/[A-Za-z]/', $password) !== 1
            || preg_match('/[^A-Za-z]/', $password) !== 1
        ) {
            throw new Problem(
                422,
                'Validation failed',
                'Password must be 12 to 1024 characters and contain letters and non-letters.',
            );
        }
    }

    private function requirePasswordLogin(): void
    {
        if (! $this->passwordLoginEnabled) {
            throw new Problem(
                410,
                'Password authentication disabled',
                'Request a passwordless email sign-in link instead.',
            );
        }
    }

    private function stepUpPurpose(string $action): string
    {
        if ($action !== 'ownership-transfer') {
            throw new Problem(422, 'Validation failed', 'The requested step-up action is not supported.');
        }

        return 'step-up-ownership';
    }

    private function assertUuid(string $id, string $field): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) !== 1) {
            throw new Problem(422, 'Validation failed', $field . ' must be a UUID.');
        }

        return strtolower($id);
    }
}
