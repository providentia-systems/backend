<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Providentia\Home\Application\HomeStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class LoginLinkService
{
    /**
     * @param list<string> $bootstrapAdministratorEmails
     * @param array{name: string, locale: string, currency: string, timezone: string} $defaultHome
     */
    public function __construct(
        private readonly LoginLinkStore $requests,
        private readonly IdentityStore $identities,
        private readonly HomeStore $homes,
        private readonly CredentialHasher $hasher,
        private readonly AccountNotificationSender $notifications,
        private readonly AuthenticationService $authentication,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly SecureTokenGenerator $tokens,
        private readonly int $requestTtlSeconds,
        private readonly int $exchangeTtlSeconds,
        private readonly int $pollIntervalSeconds,
        private readonly int $webIdleTtlSeconds,
        private readonly int $nativeIdleTtlSeconds,
        private readonly array $bootstrapAdministratorEmails,
        private readonly array $defaultHome,
        private readonly bool $exposeDevelopmentTokens = false,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{accepted: true, requestId: string, expiresAt: string, pollIntervalSeconds: int}
     */
    public function start(array $input): array
    {
        $requestId = $this->uuid((string) ($input['requestId'] ?? ''), 'requestId');
        $email = $this->email((string) ($input['email'] ?? ''));
        $application = LoginApplicationKind::fromInput((string) ($input['applicationKind'] ?? ''));
        $pollChallenge = $this->challenge((string) ($input['pollChallenge'] ?? ''), 'pollChallenge');
        $codeChallenge = $this->challenge((string) ($input['codeChallenge'] ?? ''), 'codeChallenge');
        if ((string) ($input['codeChallengeMethod'] ?? '') !== 'S256') {
            throw new Problem(422, 'Validation failed', 'Only the S256 code-challenge method is supported.');
        }
        $state = (string) ($input['state'] ?? '');
        if (strlen($state) < 32 || strlen($state) > 256) {
            throw new Problem(422, 'Validation failed', 'State must contain 32 to 256 characters.');
        }
        $installationId = $this->uuid((string) ($input['installationId'] ?? ''), 'installationId');
        $deviceName = trim((string) ($input['deviceName'] ?? ''));
        $platform = trim((string) ($input['platform'] ?? ''));
        if ($deviceName === '' || mb_strlen($deviceName) > 120) {
            throw new Problem(422, 'Validation failed', 'Device name must contain 1 to 120 characters.');
        }
        if ($platform === '' || mb_strlen($platform) > 40) {
            throw new Problem(422, 'Validation failed', 'Platform must contain 1 to 40 characters.');
        }
        $transport = mb_strtolower(trim((string) ($input['transport'] ?? '')));
        if (! in_array($transport, ['web', 'native'], true)) {
            throw new Problem(422, 'Validation failed', 'Transport must be web or native.');
        }
        $maximumIdle = $transport === 'web' ? $this->webIdleTtlSeconds : $this->nativeIdleTtlSeconds;
        if (array_key_exists('requestedSessionIdleSeconds', $input)) {
            $requestedIdle = (int) $input['requestedSessionIdleSeconds'];
            if ($requestedIdle < 900 || $requestedIdle > 5184000) {
                throw new Problem(
                    422,
                    'Validation failed',
                    'Requested session idle time must be between 900 and 5184000 seconds.',
                );
            }
            $idleTtl = $maximumIdle === 0 ? $requestedIdle : min($requestedIdle, $maximumIdle);
        } else {
            // 0 keeps the trusted installation signed in until explicit
            // sign-out, revocation, or account-level invalidation.
            $idleTtl = $maximumIdle;
        }
        $stateHash = $this->hasher->hashToken($state);
        $canonical = [
            'requestId' => $requestId,
            'email' => $email,
            'applicationKind' => $application->value,
            'pollChallenge' => $pollChallenge,
            'codeChallenge' => $codeChallenge,
            'stateHash' => $stateHash,
            'installationId' => $installationId,
            'deviceName' => $deviceName,
            'platform' => $platform,
            'transport' => $transport,
            'idleTtl' => $idleTtl,
        ];
        try {
            $requestHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new \LogicException('The login-link request could not be canonicalized.', 0, $exception);
        }

        try {
            return $this->transactions->transactional(function () use (
                $requestId,
                $email,
                $application,
                $pollChallenge,
                $codeChallenge,
                $stateHash,
                $installationId,
                $deviceName,
                $platform,
                $transport,
                $idleTtl,
                $requestHash,
            ): array {
                $existing = $this->requests->find($requestId);
                if ($existing !== null) {
                    if (! hash_equals((string) $existing['request_hash'], $requestHash)) {
                        throw new Problem(
                            409,
                            'Login request conflict',
                            'That request identifier was already used with different values.',
                        );
                    }

                    return $this->started($requestId, $this->date((string) $existing['expires_at']));
                }

                $now = $this->clock->now();
                $expiresAt = $now->add(new DateInterval('PT' . $this->requestTtlSeconds . 'S'));
                $approvalToken = $this->tokens->generate();
                $date = $this->databaseDate($now);
                $this->requests->create([
                    'id' => $requestId,
                    'request_hash' => $requestHash,
                    'normalized_email' => $email,
                    'application_kind' => $application->value,
                    'revision' => 1,
                    'installation_id' => $installationId,
                    'device_name' => mb_substr($deviceName, 0, 120),
                    'platform' => mb_substr($platform, 0, 40),
                    'transport' => $transport,
                    'refresh_idle_ttl_seconds' => $idleTtl,
                    'poll_challenge' => $pollChallenge,
                    'code_challenge' => $codeChallenge,
                    'state_hash' => $stateHash,
                    'approval_token_hash' => $this->hasher->hashToken($approvalToken),
                    'status' => 'pending',
                    'failed_proof_attempts' => 0,
                    'user_id' => null,
                    'onboarding_home_id' => null,
                    'issued_session_id' => null,
                    'expires_at' => $this->databaseDate($expiresAt),
                    'approved_at' => null,
                    'exchange_expires_at' => null,
                    'exchanged_at' => null,
                    'denied_at' => null,
                    'cancelled_at' => null,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
                $this->notifications->sendLoginLink(
                    $email,
                    $requestId,
                    $approvalToken,
                    $application,
                );

                $started = $this->started($requestId, $expiresAt);
                if ($this->exposeDevelopmentTokens) {
                    $started['developmentApprovalToken'] = $approvalToken;
                }

                return $started;
            });
        } catch (\Throwable $error) {
            $existing = $this->requests->find($requestId);
            if (
                $existing !== null
                && isset($existing['request_hash'], $existing['expires_at'])
                && hash_equals((string) $existing['request_hash'], $requestHash)
            ) {
                return $this->started($requestId, $this->date((string) $existing['expires_at']));
            }
            if ($this->requests->findByPollChallenge($pollChallenge) !== null) {
                throw new Problem(
                    409,
                    'Login request conflict',
                    'That private polling challenge is already bound to another login request.',
                );
            }

            throw $error;
        }
    }

    /** @return array{valid: true, requestId: string, applicationKind: string, expiresAt: string} */
    public function proof(string $requestId, string $approvalToken, string $applicationKind): array
    {
        $application = LoginApplicationKind::fromInput($applicationKind);
        $request = $this->approvalRequest($requestId, $approvalToken, $application);

        return [
            'valid' => true,
            'requestId' => (string) $request['id'],
            'applicationKind' => $application->value,
            'expiresAt' => $this->date((string) $request['expires_at'])->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function review(string $requestId, string $approvalToken, string $applicationKind): array
    {
        $application = LoginApplicationKind::fromInput($applicationKind);
        $request = $this->approvalRequest($requestId, $approvalToken, $application);

        return [
            'requestId' => (string) $request['id'],
            'applicationKind' => $application->value,
            'deviceName' => (string) $request['device_name'],
            'platform' => (string) $request['platform'],
            'createdAt' => $this->date((string) $request['created_at'])->format(DATE_ATOM),
            'expiresAt' => $this->date((string) $request['expires_at'])->format(DATE_ATOM),
        ];
    }

    /** @return array{status: string} */
    public function approve(
        string $requestId,
        string $approvalToken,
        string $applicationKind,
    ): array {
        // Validate and persist expiry outside the approval transaction so an
        // expired terminal state is not rolled back with the HTTP problem.
        $application = LoginApplicationKind::fromInput($applicationKind);
        $this->approvalRequest($requestId, $approvalToken, $application);
        try {
            $result = $this->transactions->transactional(function () use (
                $requestId,
                $approvalToken,
                $application,
            ): array {
                $request = $this->requests->find($requestId);
                if ($request === null || (string) $request['status'] !== 'pending') {
                    return ['status' => 'unavailable'];
                }
                $now = $this->clock->now();
                $email = (string) $request['normalized_email'];
                $this->requests->lockEmail($email);
                $user = $this->identities->findUserByEmail($email);
                if ($user !== null && (string) $user['status'] !== 'active') {
                    $this->requests->deny($requestId, $this->hasher->hashToken($approvalToken), $now);
                    return ['status' => 'inactive'];
                }
                $exchangeExpiresAt = $now->add(new DateInterval('PT' . $this->exchangeTtlSeconds . 'S'));
                if (
                    ! $this->requests->reserveApproval(
                        $requestId,
                        $this->hasher->hashToken($approvalToken),
                        $now,
                        $exchangeExpiresAt,
                    )
                ) {
                    throw new Problem(409, 'Login request unavailable', 'This login request was already handled.');
                }

                $newAccount = $user === null;
                $userId = $newAccount ? $this->ids->generate() : (string) $user['id'];
                if ($newAccount) {
                    $name = strstr($email, '@', true);
                    $this->identities->createUser(
                        $userId,
                        $email,
                        is_string($name) && $name !== '' ? mb_substr($name, 0, 120) : 'Member',
                        $this->defaultHome['locale'],
                        $this->defaultHome['timezone'],
                        $now,
                    );
                    $this->identities->markEmailVerified($userId, $now);
                    $firstVerification = true;
                } else {
                    $firstVerification = $this->identities->claimEmailVerification($userId, $now);
                }

                $onboardingHomeId = null;
                if (
                    $application === LoginApplicationKind::HOMEOWNER
                    && $firstVerification
                    && $this->homes->listForUser($userId) === []
                    && $this->homes->pendingInvitationsForEmail($email, $now) === []
                ) {
                    $onboardingHomeId = $this->ids->generate();
                    $this->homes->createHome(
                        $onboardingHomeId,
                        $userId,
                        $this->defaultHome['name'],
                        $this->defaultHome['locale'],
                        $this->defaultHome['currency'],
                        $this->defaultHome['timezone'],
                        $now,
                    );
                    $this->homes->recordAudit(
                        $this->ids->generate(),
                        $userId,
                        'home.created',
                        'home',
                        $onboardingHomeId,
                        $onboardingHomeId,
                        json_encode(['source' => 'login-link-onboarding'], JSON_THROW_ON_ERROR),
                        $now,
                    );
                }

                if (in_array($email, $this->bootstrapAdministratorEmails, true)) {
                    $this->identities->seedBootstrapAdministrator(
                        $this->ids->generate(),
                        $this->ids->generate(),
                        $userId,
                        $email,
                        $now,
                    );
                }
                $this->identities->activatePendingAdministratorGrant(
                    $this->ids->generate(),
                    $userId,
                    $email,
                    $now,
                );
                $this->requests->completeApproval($requestId, $userId, $onboardingHomeId, $now);

                return ['status' => 'approved'];
            });
        } catch (ConcurrentPlatformRoleChange) {
            throw new Problem(409, 'Login request conflict', 'The account role changed during approval.');
        }
        if ($result['status'] === 'inactive') {
            throw new Problem(403, 'Login unavailable', 'This login request cannot be approved.');
        }
        if ($result['status'] !== 'approved') {
            throw new Problem(409, 'Login request unavailable', 'This login request was already handled.');
        }

        return $result;
    }

    /** @return array{status: string} */
    public function deny(
        string $requestId,
        string $approvalToken,
        string $applicationKind,
    ): array {
        $application = LoginApplicationKind::fromInput($applicationKind);
        $this->approvalRequest($requestId, $approvalToken, $application);
        $denied = $this->transactions->transactional(function () use ($requestId, $approvalToken): bool {
            return $this->requests->deny(
                $requestId,
                $this->hasher->hashToken($approvalToken),
                $this->clock->now(),
            );
        });
        if (! $denied) {
            throw new Problem(409, 'Login request unavailable', 'This login request was already handled.');
        }

        return ['status' => 'denied'];
    }

    /** @return array<string, mixed> */
    public function status(string $requestId, string $pollToken): array
    {
        return $this->transactions->transactional(function () use ($requestId, $pollToken): array {
            $request = $this->pollRequest($requestId, $pollToken);
            $this->expire($request);
            $request = $this->requests->find($requestId) ?? $request;

            return $this->statusResponse($request);
        });
    }

    /** @return array<string, mixed> */
    public function cancel(string $requestId, string $pollToken): array
    {
        return $this->transactions->transactional(function () use ($requestId, $pollToken): array {
            $request = $this->pollRequest($requestId, $pollToken);
            $this->expire($request);
            $request = $this->requests->find($requestId) ?? $request;
            if (in_array((string) $request['status'], ['pending', 'approved'], true)) {
                $this->requests->cancel($requestId, $this->clock->now());
                $request = $this->requests->find($requestId) ?? $request;
            }

            return $this->statusResponse($request);
        });
    }

    /** @return array<string, mixed> */
    public function exchange(string $requestId, string $pollToken, string $codeVerifier, string $state): array
    {
        // Polling commits any expiry transition before exchange validation.
        $status = $this->status($requestId, $pollToken);
        if (($status['status'] ?? null) === 'expired') {
            throw new Problem(410, 'Login request expired', 'Request a new login link from Providentia.');
        }
        $result = $this->transactions->transactional(function () use (
            $requestId,
            $pollToken,
            $codeVerifier,
            $state,
        ): array {
            $request = $this->pollRequest($requestId, $pollToken);
            if ((string) $request['status'] !== 'approved') {
                return ['problem' => 'unavailable'];
            }
            $validState = hash_equals((string) $request['state_hash'], $this->hasher->hashToken($state));
            $validVerifier = preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $codeVerifier) === 1
                && hash_equals((string) $request['code_challenge'], $this->s256($codeVerifier));
            if (! $validState || ! $validVerifier) {
                $this->requests->recordFailedProof($requestId, $this->clock->now());
                return ['problem' => 'proof'];
            }
            $now = $this->clock->now();
            if (! $this->requests->reserveExchange($requestId, $now)) {
                return ['problem' => 'unavailable'];
            }
            $userId = (string) ($request['user_id'] ?? '');
            $user = $this->identities->findUserById($userId);
            if ($user === null || (string) $user['status'] !== 'active' || $user['email_verified_at'] === null) {
                if (! $this->requests->failExchange($requestId, $now)) {
                    throw new \RuntimeException('The unavailable login-link exchange was not cancelled.');
                }

                return ['problem' => 'account-unavailable'];
            }
            $homes = $this->homes->listForUser($userId);
            $activeHomeId = $this->selectActiveHome($userId, $homes);
            $session = $this->authentication->issueLoginLinkSession(
                $userId,
                (string) $request['installation_id'],
                (string) $request['device_name'],
                (string) $request['platform'],
                (string) $request['transport'],
                (int) $request['refresh_idle_ttl_seconds'],
                $activeHomeId,
            );
            $this->requests->completeExchange($requestId, (string) $session['sessionId'], $now);

            return $session;
        });
        if (($result['problem'] ?? null) === 'proof') {
            throw new Problem(401, 'Login proof rejected', 'The private login proof is invalid.');
        }
        if (($result['problem'] ?? null) === 'unavailable') {
            throw new Problem(
                409,
                'Login request unavailable',
                'This login request is not approved or was already exchanged.',
            );
        }
        if (($result['problem'] ?? null) === 'account-unavailable') {
            throw new Problem(
                409,
                'Login request unavailable',
                'The approved account is no longer available. Request a new login link.',
            );
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function approvalRequest(
        string $requestId,
        string $approvalToken,
        LoginApplicationKind $application,
    ): array {
        $requestId = $this->uuid($requestId, 'requestId');
        $request = $this->requests->find($requestId);
        if (
            $request === null
            || ! isset($request['application_kind'])
            || ! hash_equals((string) $request['application_kind'], $application->value)
            || $approvalToken === ''
            || $request['approval_token_hash'] === null
            || ! hash_equals(
                (string) $request['approval_token_hash'],
                $this->hasher->hashToken($approvalToken),
            )
        ) {
            throw new Problem(404, 'Login request unavailable', 'This login request is invalid or unavailable.');
        }
        $this->expire($request);
        $request = $this->requests->find($requestId) ?? $request;
        if ((string) $request['status'] === 'expired') {
            throw new Problem(410, 'Login request expired', 'Request a new login link from Providentia.');
        }
        if ((string) $request['status'] !== 'pending') {
            throw new Problem(409, 'Login request unavailable', 'This login request was already handled.');
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function pollRequest(string $requestId, string $pollToken): array
    {
        $requestId = mb_strtolower(trim($requestId));
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $requestId,
            ) !== 1
        ) {
            throw new Problem(401, 'Login proof rejected', 'The private login proof is invalid.');
        }
        $request = $this->requests->find($requestId);
        if (
            $request === null
            || strlen($pollToken) < 43
            || strlen($pollToken) > 128
            || ! hash_equals((string) $request['poll_challenge'], $this->s256($pollToken))
        ) {
            throw new Problem(401, 'Login proof rejected', 'The private login proof is invalid.');
        }

        return $request;
    }

    /** @param array<string, mixed> $request */
    private function expire(array $request): void
    {
        $now = $this->clock->now();
        $status = (string) $request['status'];
        $deadline = $status === 'approved'
            ? ($request['exchange_expires_at'] === null
                ? null
                : $this->date((string) $request['exchange_expires_at']))
            : $this->date((string) $request['expires_at']);
        if (in_array($status, ['pending', 'approved'], true) && $deadline !== null && $deadline <= $now) {
            $this->requests->expire((string) $request['id'], $now);
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function statusResponse(array $request): array
    {
        $status = (string) $request['status'];
        if ($status === 'approving') {
            $status = 'pending';
        } elseif ($status === 'exchanging') {
            $status = 'exchanged';
        }
        $response = [
            'requestId' => (string) $request['id'],
            'applicationKind' => (string) $request['application_kind'],
            'status' => $status,
            // This is the immutable request expiry returned by start(). The
            // shorter post-approval exchange deadline remains server-private
            // and is still enforced by expire().
            'expiresAt' => $this->date((string) $request['expires_at'])->format(DATE_ATOM),
        ];
        if ($request['approved_at'] !== null) {
            $response['approvedAt'] = $this->date((string) $request['approved_at'])->format(DATE_ATOM);
        }

        return $response;
    }

    /** @param list<array<string, mixed>> $homes */
    private function selectActiveHome(string $userId, array $homes): ?string
    {
        $latest = $this->identities->latestActiveHomeId($userId, $this->clock->now());
        $ids = array_map(static fn (array $home): string => (string) $home['id'], $homes);
        if ($latest !== null && in_array($latest, $ids, true)) {
            return $latest;
        }

        return count($ids) === 1 ? $ids[0] : null;
    }

    /** @return array{accepted: true, requestId: string, expiresAt: string, pollIntervalSeconds: int} */
    private function started(string $requestId, DateTimeImmutable $expiresAt): array
    {
        return [
            'accepted' => true,
            'requestId' => $requestId,
            'expiresAt' => $expiresAt->format(DATE_ATOM),
            'pollIntervalSeconds' => $this->pollIntervalSeconds,
        ];
    }

    private function email(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new Problem(422, 'Validation failed', 'A valid email address is required.');
        }

        return $email;
    }

    private function uuid(string $value, string $field): string
    {
        $value = mb_strtolower(trim($value));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) !== 1) {
            throw new Problem(422, 'Validation failed', $field . ' must be a UUID.');
        }

        return $value;
    }

    private function challenge(string $value, string $field): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $value) !== 1) {
            throw new Problem(422, 'Validation failed', $field . ' must be an S256 base64url challenge.');
        }

        return $value;
    }

    private function s256(string $value): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $value, true)), '+/', '-_'), '=');
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function databaseDate(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
