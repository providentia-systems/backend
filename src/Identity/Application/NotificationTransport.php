<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

interface NotificationTransport
{
    /** @param array<string, scalar|null> $context */
    public function deliver(string $template, string $recipient, array $context): void;
}
