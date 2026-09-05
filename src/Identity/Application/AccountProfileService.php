<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use DateInterval;
use DateTimeZone;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\Geography\Application\CountryService;
use Providentia\Home\Application\HomeStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class AccountProfileService
{
    public function __construct(
        private readonly AccountProfileStore $profiles,
        private readonly IdentityStore $identities,
        private readonly HomeStore $homes,
        private readonly CountryService $countries,
        private readonly AccessService $access,
        private readonly AccessStore $accessStore,
        private readonly EmailCodeService $codes,
        private readonly CredentialHasher $hasher,
        private readonly SecureTokenGenerator $tokens,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly UuidGenerator $ids,
    ) {
    }

    /**
     * @return array<string, mixed> */
    public function get(
        AuthenticatedIdentity $identity,
    ): array {
        $profile = $this->profiles->profile($identity->userId);
        return [
            'userId' => $identity->userId,
            'displayName' => $profile['display_name'] ?? '',
            'locale' => $profile['locale'] ?? 'en',
            'timezone' => $profile['timezone'] ?? 'UTC',
            'countryCode' => $profile['country_code'] ?? null,
            'stateId' => isset($profile['state_id'])
                ? (int) $profile['state_id']
                : null,
            'cityId' => isset($profile['city_id'])
                ? (int) $profile['city_id']
                : null,
            'revision' => (int) ($profile['revision'] ?? 1),
            'onboardingComplete' => ($profile['onboarding_completed_at'] ?? null) !== null,
            'avatarSource' => $profile['avatar_source'] ?? 'default',
            'avatarEmailId' => $profile['avatar_email_id'] ?? null,
            'avatarRevision' => (int) ($profile['avatar_revision'] ?? 0),
            'emails' => $this->profiles->emails($identity->userId),
            'accountAccess'
                => $this->access->effective(FeatureCatalog::ACCOUNT, $identity->userId),
            'administratorAccess'
                => $this->access->effective(FeatureCatalog::ADMIN, $identity->userId),
            'administratorStatus' => $this->profiles->isSystemOwner($identity->userId)
                ? 'owner'
                : $this->profiles->administratorStatus($identity->userId),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function save(
        AuthenticatedIdentity $identity,
        array $input,
        bool $onboarding,
    ): array {
        $name = trim((string) ($input['displayName'] ?? ''));
        $place = $this->countries->validatePlace($input);
        $defaults = $this->countries->published($place['country_code']);
        $timezone = (string) ($input['timezone'] ?? $defaults['default_timezone']);
        $locale = (string) ($input['locale'] ?? 'en');
        if (
            $name === '' || mb_strlen($name) > 120 || strlen($locale) > 16
            || !in_array($timezone, DateTimeZone::listIdentifiers(), true)
        ) {
            throw new Problem(
                422,
                'Invalid profile',
                'Supply your name, locale and a recognized timezone.',
            );
        }
        $profile = $this->profiles->profile($identity->userId);
        if ($onboarding && ($profile['onboarding_completed_at'] ?? null) !== null) {
            throw new Problem(
                409,
                'Already registered',
                'Your account setup is already complete.',
            );
        }
        $requiresAcceptance = $onboarding || ($profile['country_code'] ?? null) !== $place['country_code'];
        if ($requiresAcceptance && ($input['policyAccepted'] ?? false) !== true) {
            throw new Problem(
                422,
                'Agreement required',
                'Read and agree to the country privacy notice.',
            );
        }
        $this->transactions->transactional(
            function () use (
                $identity,
                $input,
                $name,
                $locale,
                $timezone,
                $place,
                $defaults,
                $requiresAcceptance,
                $onboarding,
            ): void {
                $this->accessStore->lockSubject(FeatureCatalog::ACCOUNT, $identity->userId);
                if ($requiresAcceptance) {
                    $this->countries->accept(
                        $identity->userId,
                        $place['country_code'],
                        (string) ($input['policyId'] ?? ''),
                        (int) ($input['policyRevision'] ?? 0),
                    );
                }
                $values = [
                    ...$place,
                    'display_name' => $name,
                    'locale' => $locale,
                    'timezone' => $timezone,
                    'updated_at' => $this->clock->now()
                        ->format('Y-m-d H:i:s'),
                ];
                if ($onboarding) {
                    $values['onboarding_completed_at'] = $values['updated_at'];
                }
                if (
                    !$this->profiles->update(
                        $identity->userId,
                        $values,
                        (int) ($input['expectedRevision'] ?? 0),
                    )
                ) {
                    throw new Problem(
                        409,
                        'Revision conflict',
                        'Reload your profile before saving.',
                    );
                }
                if ($onboarding) {
                    $invited = false;
                    foreach ($this->profiles->emails($identity->userId) as $email) {
                        if ($this->homes->pendingInvitationsForEmail((string) $email['email'], $this->clock->now()) !== []) {
                            $invited = true;
                        }
                    }
                    $this->access->initialize(
                        FeatureCatalog::ACCOUNT,
                        $identity->userId,
                        (string) $defaults[$invited
                            ? 'invited_group_id'
                            : 'account_group_id'],
                    );
                }
                $this->accessStore->audit(
                    $identity->userId,
                    $onboarding
                        ? 'account.registered'
                        : 'profile.updated',
                    FeatureCatalog::ACCOUNT,
                    $identity->userId,
                    [],
                );
            },
        );
        return $this->get($identity);
    }

    /**
     * @return array<string, mixed> */
    public function requestEmail(
        AuthenticatedIdentity $identity,
        string $email,
        string $ip,
    ): array {
        return $this->codes->request(
            $email,
            'email.add',
            $identity->userId,
            ['sessionId' => $identity->sessionId],
            $ip,
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function verifyEmail(
        AuthenticatedIdentity $identity,
        array $input,
        string $ip,
    ): array {
        $challenge = $this->verifiedChallenge($identity, $input, 'email.add', $ip);
        $this->transactions->transactional(
            function () use ($identity, $challenge): void {
                $this->accessStore->lockSubject(FeatureCatalog::ACCOUNT, $identity->userId);
                if (count($this->profiles->emails($identity->userId)) >= 10) {
                    throw new Problem(
                        409,
                        'Email limit reached',
                        'An account can have up to ten verified email addresses.',
                    );
                }
                $this->profiles->addEmail(
                    $this->ids->generate(),
                    $identity->userId,
                    (string) $challenge['email'],
                    $this->clock->now()
                        ->format('Y-m-d H:i:s'),
                );
                $this->accessStore->audit(
                    $identity->userId,
                    'email.added',
                    FeatureCatalog::ACCOUNT,
                    $identity->userId,
                    [],
                );
            },
        );
        return $this->get($identity);
    }

    /**
     * @return array<string, mixed> */
    public function requestSecurityCode(
        AuthenticatedIdentity $identity,
        string $action,
        string $ip,
    ): array {
        if (
            !in_array(
                $action,
                [
                'email.remove',
                'email.primary',
                'ownership-transfer',
                ],
                true,
            )
        ) {
            throw new Problem(
                422,
                'Invalid action',
                'Choose a supported account confirmation action.',
            );
        }
        $user = $this->identities->findUserById($identity->userId);
        if ($user === null) {
            throw new Problem(401, 'Account unavailable', 'Sign in again.');
        }
        return $this->codes->request(
            (string) $user['email'],
            'security',
            $identity->userId,
            [
                'sessionId' => $identity->sessionId,
                'action' => $action,
            ],
            $ip,
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{proofToken: string, action: string}
     */
    public function verifySecurityCode(
        AuthenticatedIdentity $identity,
        array $input,
        string $ip,
    ): array {
        $challenge = $this->verifiedChallenge($identity, $input, 'security', $ip);
        $action = (string) $challenge['context']['action'];
        $token = $this->tokens->generate();
        $now = $this->clock->now();
        $purpose = $action === 'ownership-transfer'
            ? 'step-up-ownership:homeowner'
            : 'security:' . $action;
        $this->transactions->transactional(
            function () use ($identity, $purpose, $token, $now): void {
                $this->identities->issueOneTimeToken(
                    $this->ids->generate(),
                    $identity->userId,
                    $purpose,
                    $this->hasher->hashToken($identity->sessionId . ':' . $token),
                    $now->add(new DateInterval('PT5M')),
                    $now,
                );
            },
        );
        return ['proofToken' => $token, 'action' => $action];
    }

    public function changeEmail(
        AuthenticatedIdentity $identity,
        string $emailId,
        string $proof,
        bool $primary,
    ): void {
        $action = $primary
            ? 'email.primary'
            : 'email.remove';
        $this->transactions->transactional(
            function () use ($identity, $emailId, $proof, $primary, $action): void {
                $this->accessStore->lockSubject(FeatureCatalog::ACCOUNT, $identity->userId);
                $user = $this->identities->consumeOneTimeToken(
                    'security:' . $action,
                    $this->hasher->hashToken($identity->sessionId . ':' . $proof),
                    $this->clock->now(),
                );
                if ($user !== $identity->userId) {
                    throw new Problem(
                        422,
                        'Confirmation required',
                        'Confirm this action with a fresh email code.',
                    );
                }
                $changed = $primary
                    ? $this->profiles->makePrimary($identity->userId, $emailId)
                    : $this->profiles->removeEmail($identity->userId, $emailId);
                if (!$changed) {
                    throw new Problem(
                        409,
                        'Email cannot be changed',
                        ('Keep at least one verified email. Choose another primary address before '
                            . 'removing the current one.'),
                    );
                }
                $this->accessStore->audit(
                    $identity->userId,
                    $action,
                    FeatureCatalog::ACCOUNT,
                    $identity->userId,
                    ['emailId' => $emailId],
                );
            },
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function verifiedChallenge(
        AuthenticatedIdentity $identity,
        array $input,
        string $purpose,
        string $ip,
    ): array {
        $challenge = $this->codes->verify(
            (string) ($input['challengeId'] ?? ''),
            (string) ($input['bindingToken'] ?? ''),
            (string) ($input['code'] ?? ''),
            $purpose,
            $ip,
        );
        if (
            $challenge['user_id'] !== $identity->userId
            || ($challenge['context']['sessionId'] ?? null) !== $identity->sessionId
        ) {
            throw new Problem(
                422,
                'Invalid confirmation',
                'This confirmation belongs to another account session.',
            );
        }
        return $challenge;
    }
}
