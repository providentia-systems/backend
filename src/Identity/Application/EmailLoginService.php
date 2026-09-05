<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use Providentia\Access\Application\AccessService;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class EmailLoginService
{
    public function __construct(
        private readonly EmailCodeService $codes,
        private readonly IdentityStore $identities,
        private readonly AccountProfileStore $profiles,
        private readonly AuthenticationService $authentication,
        private readonly AccessService $access,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly int $webIdleTtlSeconds,
        private readonly int $nativeIdleTtlSeconds,
    ) {
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function request(array $input, string $ip): array
    {
        $application = LoginApplicationKind::fromInput((string) ($input['applicationKind'] ?? ''));
        $installation = (string) ($input['installationId'] ?? '');
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $installation) !== 1) {
            throw new Problem(422, 'Invalid installation', 'A persistent installation UUID is required.');
        }
        $transport = (string) ($input['transport'] ?? 'native');
        $device = trim((string) ($input['deviceName'] ?? ''));
        $platform = trim((string) ($input['platform'] ?? ''));
        if (! in_array($transport, ['web', 'native'], true) || $device === '' || mb_strlen($device) > 120 || $platform === '' || strlen($platform) > 40) {
            throw new Problem(422, 'Invalid device', 'Supply the device name, platform and session transport.');
        }

        return $this->codes->request((string) ($input['email'] ?? ''), 'login', null, [
            'applicationKind' => $application->value,
            'installationId' => strtolower($installation),
            'deviceName' => $device,
            'platform' => $platform,
            'transport' => $transport,
        ], $ip);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function verify(array $input, string $ip): array
    {
        $challenge = $this->codes->verify(
            (string) ($input['challengeId'] ?? ''), (string) ($input['bindingToken'] ?? ''),
            (string) ($input['code'] ?? ''), 'login', $ip,
        );
        // Proof is consumed before this transaction. A failure cannot make a
        // numeric credential reusable or roll back its failed-attempt counter.
        return $this->transactions->transactional(function () use ($challenge): array {
            $email = (string) $challenge['email'];
            $user = $this->identities->findUserByEmail($email);
            $now = $this->clock->now();
            if ($user === null) {
                $userId = $this->ids->generate();
                $this->identities->createUser($userId, $email, '', 'en', 'UTC', $now);
                $this->identities->markEmailVerified($userId, $now);
                $this->profiles->addEmail($this->ids->generate(), $userId, $email, $now->format('Y-m-d H:i:s'));
            } else {
                if ($user['status'] !== 'active') {
                    throw new Problem(403, 'Account unavailable', 'This account is not active.');
                }
                $userId = (string) $user['id'];
            }
            if ($this->profiles->claimSystemOwner($userId, $email)) {
                $this->access->initialize(FeatureCatalog::ADMIN, $userId, FeatureCatalog::SYSTEM_OWNER);
            }
            /** @var array<string, string> $context */
            $context = $challenge['context'];
            if ($context['applicationKind'] === 'admin' && ! $this->profiles->isSystemOwner($userId)) {
                $this->profiles->registerAdministrator($userId, $now->format('Y-m-d H:i:s'));
            }
            return $this->authentication->issueVerifiedSession(
                $userId, $context['installationId'], $context['deviceName'], $context['platform'],
                $context['transport'],
                $context['transport'] === 'web' ? $this->webIdleTtlSeconds : $this->nativeIdleTtlSeconds,
                $this->identities->latestActiveHomeId($userId, $now),
            );
        });
    }
}
