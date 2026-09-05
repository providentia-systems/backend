<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

interface AccountNotificationSender
{
    public function sendPlatformAdministratorInvitation(string $email): void;

    public function sendHomeInvitation(
        string $email,
        string $homeName,
        string $role,
    ): void;
}
