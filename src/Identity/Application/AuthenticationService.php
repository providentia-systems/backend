<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateInterval;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\UuidGenerator;

final class AuthenticationService
{
    public function __construct(
        private readonly IdentityStore $store,
        private readonly CredentialHasher $hasher,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly SecureTokenGenerator $tokens,
        private readonly int $accessTtlSeconds,
        private readonly int $refreshTtlSeconds,
        private readonly int $webIdleTtlSeconds,
        private readonly int $nativeIdleTtlSeconds,
        private readonly \Providentia\Access\Application\AccessService $access,
    ) {
    }

    public function consumeStepUp(
        AuthenticatedIdentity $identity,
        string $token,
        string $action,
    ): void {
        $userId = $this->store->consumeOneTimeToken(
            $this->stepUpPurpose($action, LoginApplicationKind::HOMEOWNER),
            $this->hasher->hashToken($identity->sessionId . ':' . $token),
            $this->clock->now(),
        );
        if ($userId === null || !hash_equals($identity->userId, $userId)) {
            throw new Problem(
                422,
                'Invalid step-up proof',
                'The confirmation proof is invalid or expired.',
            );
        }
    }

    /**
     * @return array{
     *     accessToken: string,
     *     refreshToken: string,
     *     csrfToken: string,
     *     accessExpiresAt: string,
     *     refreshExpiresAt: string|null,
     *     idleExpiresAt: string|null,
     *     refreshIdleTtlSeconds: int|null,
     *     transport: string,
     *     activeHomeId: string|null,
     *     sessionId: string,
     *     deviceId: string,
     *     installationId: string,
     *     userId: string
     * }
     */
    public function refresh(string $refreshToken): array
    {
        $now = $this->clock->now();
        $session = $this->store->findSessionByRefreshHash($this->hasher->hashToken($refreshToken), $now);
        if ($session === null) {
            if ($this->store->revokeRefreshReplay($this->hasher->hashToken($refreshToken), $now)) {
                throw new Problem(
                    401,
                    'Credential replay detected',
                    ('The device session was revoked because a rotated refresh credential was '
                        . 'reused.'),
                );
            }
            throw new Problem(
                401,
                'Authentication failed',
                'The refresh credential is invalid or expired.',
            );
        }
        $accessToken = $this->tokens->generate();
        $nextRefreshToken = $this->tokens->generate();
        $csrfToken = $this->tokens->generate();
        $accessExpiry = $now->add(
            new DateInterval('PT' . $this->accessTtlSeconds . 'S'),
        );
        $transport = (string) ($session['transport'] ?? 'native');
        $transportMaximum = $transport === 'web'
            ? $this->webIdleTtlSeconds
            : $this->nativeIdleTtlSeconds;
        $storedIdleTtl = (int) ($session['refresh_idle_ttl_seconds'] ?? $this->refreshTtlSeconds);
        if ($transportMaximum === 0) {
            $refreshIdleTtl = $storedIdleTtl === 0
                ? 0
                : max(900, $storedIdleTtl);
        } elseif ($storedIdleTtl === 0) {
            $refreshIdleTtl = $transportMaximum;
        } else {
            $refreshIdleTtl = max(900, min($transportMaximum, $storedIdleTtl));
        }
        $refreshExpiry = $refreshIdleTtl === 0
            ? null
            : $now->add(new DateInterval('PT' . $refreshIdleTtl . 'S'));
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
        if (!$rotated) {
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
            'refreshExpiresAt' => $refreshExpiry?->format(DATE_ATOM),
            'idleExpiresAt' => $refreshExpiry?->format(DATE_ATOM),
            'refreshIdleTtlSeconds' => $refreshIdleTtl === 0
                ? null
                : $refreshIdleTtl,
            'transport' => $transport === 'web'
                ? 'web'
                : 'native',
            'activeHomeId' => ($session['active_home_id'] ?? null) === null
                ? null
                : (string) $session['active_home_id'],
            'sessionId' => (string) $session['id'],
            'deviceId' => (string) $session['device_id'],
            'installationId' => (string) ($session['installation_id'] ?? $session['device_id']),
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
            throw new Problem(
                401,
                'Authentication required',
                'A valid access credential is required.',
            );
        }
        return new AuthenticatedIdentity(
            (string) $session['user_id'],
            (string) $session['id'],
            (string) $session['device_id'],
            $session['active_home_id'] === null
                ? null
                : (string) $session['active_home_id'],
            [],
            array_keys(
                array_filter(
                    $this->access->effective('admin', (string) $session['user_id'])['features'],
                    static fn(mixed $enabled): bool => $enabled === true,
                ),
            ),
        );
    }

    /** @return list<array<string, mixed>> */
    public function listSessions(
        AuthenticatedIdentity $identity,
    ): array {
        return array_map(
            static function (array $session) use ($identity): array {
                $session['current'] = (string) ($session['id'] ?? '') === $identity->sessionId;
                return $session;
            },
            $this->store->listSessions($identity->userId),
        );
    }

    /** @return array<string, mixed> */
    public function issueVerifiedSession(
        string $userId,
        string $installationId,
        string $deviceName,
        string $platform,
        string $transport,
        int $refreshIdleTtlSeconds,
        ?string $activeHomeId,
    ): array {
        return $this->issueSession(
            $userId,
            $this->accountScopedDeviceId($userId, $installationId),
            $deviceName,
            $platform,
            $transport,
            $refreshIdleTtlSeconds,
            $installationId,
            $activeHomeId,
        );
    }

    public function revokeSession(
        AuthenticatedIdentity $identity,
        string $sessionId,
    ): void {
        if (
            !$this->store->revokeSession(
                $identity->userId,
                $sessionId,
                $this->clock->now(),
            )
        ) {
            throw new Problem(
                404,
                'Not found',
                'The requested session is unavailable.',
            );
        }
    }

    public function revokeSessionByRefreshProof(
        string $refreshToken,
        string $csrfToken,
    ): bool {
        if ($refreshToken === '' || $csrfToken === '') {
            return false;
        }
        return $this->store->revokeSessionByRefreshProof(
            $this->hasher->hashToken($refreshToken),
            $this->hasher->hashToken($csrfToken),
            $this->clock->now(),
        );
    }

    public function revokeSessionByRefreshToken(string $refreshToken): bool
    {
        if ($refreshToken === '') {
            return false;
        }
        return $this->store->revokeSessionByRefreshHash(
            $this->hasher->hashToken($refreshToken),
            $this->clock->now(),
        );
    }

    public function verifyCsrf(
        AuthenticatedIdentity $identity,
        string $token,
    ): bool {
        return $token !== '' && $this->store->verifyCsrf(
            $identity->sessionId,
            $this->hasher->hashToken($token),
        );
    }

    /**
     * @return array{
     *     accessToken: string,
     *     refreshToken: string,
     *     csrfToken: string,
     *     accessExpiresAt: string,
     *     refreshExpiresAt: string|null,
     *     idleExpiresAt: string|null,
     *     refreshIdleTtlSeconds: int|null,
     *     transport: string,
     *     activeHomeId: string|null,
     *     sessionId: string,
     *     deviceId: string,
     *     installationId: string,
     *     userId: string
     * }
     */
    private function issueSession(
        string $userId,
        string $deviceId,
        string $deviceName,
        string $platform,
        string $transport = 'native',
        ?int $refreshIdleTtlSeconds = null,
        ?string $installationId = null,
        ?string $activeHomeId = null,
    ): array {
        $transport = $this->normalizeTransport($transport);
        $now = $this->clock->now();
        $accessToken = $this->tokens->generate();
        $refreshToken = $this->tokens->generate();
        $accessExpiry = $now->add(
            new DateInterval('PT' . $this->accessTtlSeconds . 'S'),
        );
        $refreshIdleTtlSeconds = $this->requestedIdleTtl($transport, $refreshIdleTtlSeconds);
        $refreshExpiry = $refreshIdleTtlSeconds === 0
            ? null
            : $now->add(
                new DateInterval('PT' . $refreshIdleTtlSeconds . 'S'),
            );
        $sessionId = $this->ids->generate();
        $csrfToken = $this->tokens->generate();
        $storedDeviceName = $deviceName === ''
            ? 'Unnamed device'
            : mb_substr($deviceName, 0, 120);
        $storedPlatform = $platform === ''
            ? 'unknown'
            : mb_substr($platform, 0, 40);
        $accessHash = $this->hasher->hashToken($accessToken);
        $refreshHash = $this->hasher->hashToken($refreshToken);
        $csrfHash = $this->hasher->hashToken($csrfToken);
        $this->store->createSession(
            $sessionId,
            $userId,
            $deviceId,
            $storedDeviceName,
            $storedPlatform,
            $accessHash,
            $refreshHash,
            $csrfHash,
            $accessExpiry,
            $refreshExpiry,
            $now,
            $transport,
            $refreshIdleTtlSeconds,
            $installationId,
            $activeHomeId,
        );
        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'csrfToken' => $csrfToken,
            'accessExpiresAt' => $accessExpiry->format(DATE_ATOM),
            'refreshExpiresAt' => $refreshExpiry?->format(DATE_ATOM),
            'idleExpiresAt' => $refreshExpiry?->format(DATE_ATOM),
            'refreshIdleTtlSeconds' => $refreshIdleTtlSeconds === 0
                ? null
                : $refreshIdleTtlSeconds,
            'transport' => $transport,
            'activeHomeId' => $activeHomeId,
            'sessionId' => $sessionId,
            'deviceId' => $deviceId,
            'installationId' => $installationId ?? $deviceId,
            'userId' => $userId,
        ];
    }

    private function accountScopedDeviceId(
        string $userId,
        string $installationId,
    ): string {
        $hex = hash('sha256', $userId . "\x00" . $installationId);
        $hex = substr($hex, 0, 12) . '8' . substr($hex, 13, 3) . dechex(hexdec($hex[16]) & 0x3 | 0x8)
            . substr($hex, 17, 15);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function normalizeTransport(string $transport): string
    {
        $transport = mb_strtolower(trim($transport));
        if (!in_array($transport, ['web', 'native'], true)) {
            throw new Problem(
                422,
                'Validation failed',
                'Transport must be web or native.',
            );
        }
        return $transport;
    }

    private function requestedIdleTtl(
        string $transport,
        ?int $requested,
    ): int {
        $maximum = $transport === 'web'
            ? $this->webIdleTtlSeconds
            : $this->nativeIdleTtlSeconds;
        if ($requested === null || $requested === 0) {
            return $maximum;
        }
        if ($requested < 900 || $requested > 5184000) {
            throw new Problem(
                422,
                'Validation failed',
                'Requested session idle time must be between 900 and 5184000 seconds.',
            );
        }
        return $maximum === 0
            ? $requested
            : min($requested, $maximum);
    }

    private function stepUpPurpose(
        string $action,
        LoginApplicationKind $application,
    ): string {
        if ($action !== 'ownership-transfer') {
            throw new Problem(
                422,
                'Validation failed',
                'The requested step-up action is not supported.',
            );
        }
        if ($application !== LoginApplicationKind::HOMEOWNER) {
            throw new Problem(
                422,
                'Validation failed',
                'The requested action is not available in that application.',
            );
        }
        return $this->oneTimePurpose('step-up-ownership', $application);
    }

    private function oneTimePurpose(
        string $purpose,
        LoginApplicationKind $application,
    ): string {
        return $purpose . ':' . $application->value;
    }
}
