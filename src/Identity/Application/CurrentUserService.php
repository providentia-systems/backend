<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use Providentia\Home\Application\HomeStore;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;

final class CurrentUserService
{
    public function __construct(
        private readonly IdentityStore $identities,
        private readonly HomeStore $homes,
        private readonly Clock $clock,
        private readonly AccountProfileService $profiles,
        private readonly AccountProfileStore $profileStore,
    ) {
    }

    /** @return array<string, mixed> */
    public function bootstrap(AuthenticatedIdentity $identity): array
    {
        $profile = $this->identities->profile($identity->userId);
        if ($profile === null || (string) $profile['status'] !== 'active') {
            throw new Problem(401, 'Authentication required', 'The account is unavailable.');
        }
        $homes = $this->homes->listForUser($identity->userId);
        $homeIds = array_map(static fn (array $home): string => (string) $home['id'], $homes);
        $activeHomeId = $identity->activeHomeId;
        if ($activeHomeId !== null && ! in_array($activeHomeId, $homeIds, true)) {
            $this->identities->clearActiveHome($identity->userId, $activeHomeId, $this->clock->now());
            $activeHomeId = null;
        }
        $sessions = $this->identities->listSessions($identity->userId);
        $currentSession = null;
        foreach ($sessions as $session) {
            if ((string) $session['id'] !== $identity->sessionId) {
                continue;
            }
            $session['current'] = true;
            $currentSession = $session;
            break;
        }
        if ($currentSession === null) {
            throw new Problem(401, 'Authentication required', 'The current device session is unavailable.');
        }
        $pendingInvitations = [];
        foreach ($this->profileStore->emails($identity->userId) as $email) {
            foreach ($this->homes->pendingInvitationsForEmail((string) $email['email'], $this->clock->now()) as $invitation) {
                $pendingInvitations[(string) $invitation['id']] = $invitation;
            }
        }

        return [
            'userId' => $identity->userId,
            'email' => (string) $profile['email'],
            'emailVerified' => $profile['emailVerifiedAt'] !== null,
            'displayName' => $profile['displayName'],
            'locale' => $profile['locale'],
            'timezone' => $profile['timezone'],
            'activeHomeId' => $activeHomeId,
            'homes' => $homes,
            'pendingInvitations' => array_values($pendingInvitations),
            'profile' => $this->profiles->get($identity),
            'platformRoles' => $identity->platformRoles,
            'currentSession' => $currentSession,
        ];
    }
}
