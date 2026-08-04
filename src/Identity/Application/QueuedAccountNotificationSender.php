<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\UuidGenerator;

final class QueuedAccountNotificationSender implements AccountNotificationSender
{
    public function __construct(
        private readonly NotificationOutbox $outbox,
        private readonly UuidGenerator $ids,
        private readonly Clock $clock,
    ) {
    }

    public function sendMagicLink(string $email, string $token): void
    {
        $this->enqueue('magic-link', $email, ['token' => $token]);
    }

    public function sendStepUpLink(string $email, string $token, string $action): void
    {
        $this->enqueue('step-up-link', $email, ['token' => $token, 'action' => $action]);
    }

    public function sendEmailVerification(string $email, string $token): void
    {
        $this->enqueue('email-verification', $email, ['token' => $token]);
    }

    public function sendPasswordReset(string $email, string $token): void
    {
        $this->enqueue('password-reset', $email, ['token' => $token]);
    }

    public function sendHomeInvitation(
        string $email,
        string $homeName,
        string $role,
        string $token,
    ): void {
        $this->enqueue('home-invitation', $email, [
            'homeName' => $homeName,
            'role' => $role,
            'token' => $token,
        ]);
    }

    /** @param array<string, scalar|null> $context */
    private function enqueue(string $template, string $recipient, array $context): void
    {
        $this->outbox->enqueue(
            $this->ids->generate(),
            $template,
            $recipient,
            $context,
            $this->clock->now(),
        );
    }
}
