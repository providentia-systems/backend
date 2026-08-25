<?php

declare(strict_types=1);

namespace Providentia\Identity\Infrastructure\Notification;

use Providentia\Identity\Application\AccountNotificationSender;
use Providentia\Identity\Application\LoginApplicationKind;
use Providentia\Identity\Application\NotificationTransport;
use RuntimeException;

final class SmtpAccountNotificationSender implements AccountNotificationSender, NotificationTransport
{
    /** @param array{homeowner: string, admin: string} $loginApplicationLinks */
    public function __construct(
        private readonly string $dsn,
        private readonly string $from,
        private readonly string $publicBaseUrl,
        private readonly array $loginApplicationLinks,
    ) {
    }

    public function sendLoginLink(
        string $email,
        string $requestId,
        string $approvalToken,
        LoginApplicationKind $application,
    ): void {
        $this->deliver('login-link', $email, [
            'requestId' => $requestId,
            'approvalToken' => $approvalToken,
            'applicationKind' => $application->value,
        ]);
    }

    public function sendStepUpLink(string $email, string $token, string $action): void
    {
        $this->deliver('step-up-link', $email, ['token' => $token, 'action' => $action]);
    }

    public function sendEmailVerification(string $email, string $token): void
    {
        $this->deliver('email-verification', $email, ['token' => $token]);
    }

    public function sendPasswordReset(string $email, string $token): void
    {
        $this->deliver('password-reset', $email, ['token' => $token]);
    }

    public function sendPlatformAdministratorInvitation(string $email): void
    {
        $this->deliver('platform-administrator-invitation', $email, []);
    }

    public function sendHomeInvitation(
        string $email,
        string $homeName,
        string $role,
    ): void {
        $this->deliver('home-invitation', $email, [
            'homeName' => $homeName,
            'role' => $role,
        ]);
    }

    public function deliver(string $template, string $recipient, array $context): void
    {
        $token = rawurlencode((string) ($context['token'] ?? ''));
        $loginApplication = LoginApplicationKind::tryFrom(
            (string) ($context['applicationKind'] ?? ''),
        );
        if ($template === 'login-link' && $loginApplication === null) {
            throw new RuntimeException('Login notification has no valid application kind.');
        }
        $loginApplicationLink = $loginApplication === null
            ? ''
            : $this->loginApplicationLinks[$loginApplication->value];
        [$subject, $body] = match ($template) {
            'login-link' => [
                'Approve your Providentia login',
                sprintf(
                    "Review this login request in the %s application:\n%s#requestId=%s&approval=%s\n\n"
                    . 'The credential is single-use and does not sign a browser or the backend in.',
                    $loginApplication?->value,
                    $loginApplicationLink,
                    rawurlencode((string) ($context['requestId'] ?? '')),
                    rawurlencode((string) ($context['approvalToken'] ?? '')),
                ),
            ],
            'email-verification' => [
                'Verify your Providentia account',
                "Verify your account:\n" . $this->publicBaseUrl . '/verify-email?token=' . $token,
            ],
            'step-up-link' => [
                'Confirm a sensitive Providentia action',
                sprintf(
                    "Confirm %s:\n%s/step-up?token=%s&action=%s",
                    (string) ($context['action'] ?? 'sensitive action'),
                    $this->publicBaseUrl,
                    $token,
                    rawurlencode((string) ($context['action'] ?? '')),
                ),
            ],
            'password-reset' => [
                'Reset your Providentia password',
                "Reset your password:\n" . $this->publicBaseUrl . '/password-reset?token=' . $token,
            ],
            'platform-administrator-invitation' => [
                'You were invited to administer Providentia',
                'Open Providentia and request a login link using this exact email address. '
                . 'Your platform-administrator access will activate after verification.',
            ],
            'home-invitation' => [
                'You were invited to a Providentia home',
                sprintf(
                    "You were invited to %s as %s.\n"
                    . 'Open Providentia and request a login link using this exact email address. '
                    . 'The pending home invitation will appear after you sign in.',
                    (string) ($context['homeName'] ?? 'a Providentia home'),
                    (string) ($context['role'] ?? 'member'),
                ),
            ],
            default => throw new RuntimeException('Unsupported notification template.'),
        };
        $this->send($recipient, $subject, $body);
    }

    private function send(string $recipient, string $subject, string $body): void
    {
        $parts = parse_url($this->dsn);
        if (
            $parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array($parts['scheme'], ['smtp', 'smtps'], true)
        ) {
            throw new RuntimeException('MAIL_DSN must use smtp:// or smtps://.');
        }
        $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'smtps' ? 465 : 25));
        $transport = $parts['scheme'] === 'smtps' ? 'ssl://' : '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $parts['host'],
            ],
        ]);
        $socket = stream_socket_client(
            $transport . $parts['host'] . ':' . $port,
            $errorCode,
            $errorMessage,
            10,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (! is_resource($socket)) {
            throw new RuntimeException('SMTP connection failed: ' . $errorCode . ' ' . $errorMessage);
        }
        stream_set_timeout($socket, 10);
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO providentia', [250]);
            if (isset($parts['user'])) {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode(rawurldecode($parts['user'])), [334]);
                $this->command($socket, base64_encode(rawurldecode((string) ($parts['pass'] ?? ''))), [235]);
            }
            $this->command($socket, 'MAIL FROM:<' . $this->from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $message = implode("\r\n", [
                'From: Providentia <' . $this->from . '>',
                'To: <' . $recipient . '>',
                'Subject: ' . $subject,
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body),
            ]);
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @param list<int> $codes
     */
    private function command($socket, string $command, array $codes): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $codes);
    }

    /**
     * @param resource $socket
     * @param list<int> $codes
     */
    private function expect($socket, array $codes): void
    {
        $response = '';
        do {
            $line = fgets($socket);
            if ($line === false) {
                throw new RuntimeException('SMTP server closed the connection.');
            }
            $response .= $line;
        } while (strlen($line) >= 4 && $line[3] === '-');
        $code = (int) substr($response, 0, 3);
        if (! in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP command failed with status ' . $code . '.');
        }
    }
}
