<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

interface AccountNotificationSender
{
    public function sendMagicLink(string $email, string $token): void;

    public function sendStepUpLink(string $email, string $token, string $action): void;

    public function sendEmailVerification(string $email, string $token): void;

    public function sendPasswordReset(string $email, string $token): void;

    public function sendHomeInvitation(
        string $email,
        string $homeName,
        string $role,
        string $token,
    ): void;
}
