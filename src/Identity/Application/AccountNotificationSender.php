<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

interface AccountNotificationSender
{
    public function sendLoginLink(
        string $email,
        string $requestId,
        string $approvalToken,
        LoginApplicationKind $application,
    ): void;

    public function sendStepUpLink(
        string $email,
        string $token,
        string $action,
        LoginApplicationKind $application,
    ): void;

    public function sendPlatformAdministratorInvitation(string $email): void;

    public function sendHomeInvitation(
        string $email,
        string $homeName,
        string $role,
    ): void;
}
