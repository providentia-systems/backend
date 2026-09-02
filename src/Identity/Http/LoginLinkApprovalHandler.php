<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use JsonException;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\HtmlResponse;
use Providentia\Identity\Application\LoginApplicationKind;
use Providentia\Identity\Application\LoginLinkService;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\SecureTokenGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LoginLinkApprovalHandler implements RequestHandlerInterface
{
    private const APPROVAL_COOKIE = 'providentia_login_link_approval';
    private const CSRF_COOKIE = 'providentia_login_link_csrf';

    public function __construct(
        private readonly LoginLinkService $loginLinks,
        private readonly SecureTokenGenerator $tokens,
        private readonly string $action,
        private readonly string $publicOrigin,
        private readonly bool $cookieSecure = true,
        private readonly int $cookieTtlSeconds = 900,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return match ($this->action) {
                'launch' => $this->launch($request),
                'capture' => $this->capture($request),
                'review' => $this->review($request),
                'approve' => $this->decision($request, true),
                'deny' => $this->decision($request, false),
                default => throw new \LogicException('Unknown login-link browser action.'),
            };
        } catch (Problem $problem) {
            if (! in_array($problem->status, [403, 404, 409, 410], true)) {
                throw $problem;
            }

            return $this->problemResult($request, $problem);
        }
    }

    /** @throws JsonException */
    private function launch(ServerRequestInterface $request): ResponseInterface
    {
        [, , $path] = $this->routeContext($request);
        $nonce = $this->tokens->generate();
        $capturePath = json_encode(
            $path . '/capture',
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
        $escapedNonce = $this->escape($nonce);
        $content = <<<'HTML'
            <p class="eyebrow">Login link</p>
            <h1 id="login-title">Preparing login review</h1>
            <p>The link is being prepared for a secure, explicit approval.</p>
            <p class="muted">Opening this page alone does not approve the login.</p>
            <noscript>JavaScript is required to remove the private credential from the address bar.</noscript>
            HTML;
        $script = <<<HTML
            <script nonce="{$escapedNonce}">
            (() => {
                const fragment = new URLSearchParams(window.location.hash.slice(1));
                const approval = fragment.get('approval') || '';
                window.history.replaceState(null, '', window.location.pathname);
                const form = document.createElement('form');
                form.method = 'post';
                form.action = {$capturePath};
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'approval';
                input.value = approval;
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            })();
            </script>
            HTML;

        return $this->secure(new HtmlResponse($this->page(
            'Prepare Providentia login review',
            $content,
            $nonce,
            $script,
        )), $nonce, true);
    }

    private function capture(ServerRequestInterface $request): ResponseInterface
    {
        [, , $path] = $this->routeContext($request);
        $this->assertBrowserOrigin($request, true);
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $contentType = mb_strtolower($request->getHeaderLine('Content-Type'));
        $approvalToken = str_starts_with($contentType, 'application/x-www-form-urlencoded')
            && array_keys($body) === ['approval']
            ? (string) $body['approval']
            : '';
        $validToken = preg_match('/^[A-Za-z0-9_-]{40,128}$/', $approvalToken) === 1;

        return $this->secure((new EmptyResponse(303))
            ->withHeader('Location', $path . '/review')
            ->withAddedHeader(
                'Set-Cookie',
                $this->cookie(
                    self::APPROVAL_COOKIE,
                    $validToken ? $approvalToken : 'deleted',
                    $path,
                    $validToken ? $this->cookieTtlSeconds : 0,
                ),
            ));
    }

    private function review(ServerRequestInterface $request): ResponseInterface
    {
        [$application, $requestId, $path] = $this->routeContext($request);
        $this->assertBrowserOrigin($request, false);
        $approvalToken = (string) ($request->getCookieParams()[self::APPROVAL_COOKIE] ?? '');
        $details = $this->loginLinks->review($requestId, $approvalToken, $application->value);
        $csrf = $this->tokens->generate();
        $nonce = $this->tokens->generate();
        $applicationName = $application === LoginApplicationKind::ADMIN
            ? 'Providentia Admin'
            : 'Providentia';
        $escapedCsrf = $this->escape($csrf);
        $escapedApplicationName = $this->escape($applicationName);
        $escapedDeviceName = $this->escape((string) $details['deviceName']);
        $escapedPlatform = $this->escape((string) $details['platform']);
        $escapedCreatedAt = $this->escape((string) $details['createdAt']);
        $escapedExpiresAt = $this->escape((string) $details['expiresAt']);
        $escapedPath = $this->escape($path);
        $content = <<<HTML
            <p class="eyebrow">Login link</p>
            <h1 id="login-title">Approve this login?</h1>
            <p class="warning">Approve only if you started this login from {$escapedApplicationName}.</p>
            <dl>
                <div><dt>Device</dt><dd>{$escapedDeviceName}</dd></div>
                <div><dt>Platform</dt><dd>{$escapedPlatform}</dd></div>
                <div><dt>Requested</dt><dd>{$escapedCreatedAt}</dd></div>
                <div><dt>Approval expires</dt><dd>{$escapedExpiresAt}</dd></div>
            </dl>
            <div class="actions">
                <form method="post" action="{$escapedPath}/approve">
                    <input type="hidden" name="csrf" value="{$escapedCsrf}">
                    <button class="button approve" type="submit">Approve login</button>
                </form>
                <form method="post" action="{$escapedPath}/deny">
                    <input type="hidden" name="csrf" value="{$escapedCsrf}">
                    <button class="button deny" type="submit">Deny</button>
                </form>
            </div>
            <p class="muted">
                This browser will not be signed in. Only the application that requested the link can finish login.
            </p>
            HTML;

        return $this->secure((new HtmlResponse($this->page(
            'Review Providentia login',
            $content,
            $nonce,
        )))->withAddedHeader(
            'Set-Cookie',
            $this->cookie(self::CSRF_COOKIE, $csrf, $path, $this->cookieTtlSeconds),
        ), $nonce);
    }

    private function decision(ServerRequestInterface $request, bool $approve): ResponseInterface
    {
        [$application, $requestId, $path] = $this->routeContext($request);
        $this->assertBrowserOrigin($request, true);
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $contentType = mb_strtolower($request->getHeaderLine('Content-Type'));
        $cookies = $request->getCookieParams();
        $submittedCsrf = str_starts_with($contentType, 'application/x-www-form-urlencoded')
            && array_keys($body) === ['csrf']
            ? (string) $body['csrf']
            : '';
        $cookieCsrf = (string) ($cookies[self::CSRF_COOKIE] ?? '');
        if ($submittedCsrf === '' || $cookieCsrf === '' || ! hash_equals($cookieCsrf, $submittedCsrf)) {
            throw new Problem(403, 'Approval rejected', 'The browser confirmation expired or is invalid.');
        }
        $approvalToken = (string) ($cookies[self::APPROVAL_COOKIE] ?? '');
        if ($approve) {
            $this->loginLinks->approve($requestId, $approvalToken, $application->value);
        } else {
            $this->loginLinks->deny($requestId, $approvalToken, $application->value);
        }

        $nonce = $this->tokens->generate();
        $heading = $approve ? 'Login approved' : 'Login denied';
        $message = $approve
            ? 'Return to the application that requested the login. You can close this page.'
            : 'No application session was created. You can close this page.';
        $response = new HtmlResponse($this->resultPage($heading, $message, $nonce));

        return $this->secure($this->clearCookies($response, $path), $nonce);
    }

    private function problemResult(ServerRequestInterface $request, Problem $problem): ResponseInterface
    {
        $application = rawurlencode((string) $request->getAttribute('applicationKind', 'invalid'));
        $requestId = rawurlencode((string) $request->getAttribute('requestId', 'invalid'));
        $path = '/login-links/' . $application . '/' . $requestId;
        [$heading, $message] = match ($problem->status) {
            410 => ['Login link expired', 'Request a new login link in Providentia. You can close this page.'],
            409 => ['Login link already handled', 'Return to the requesting application or request a new login link.'],
            default => [
                'Login link unavailable',
                'This confirmation is invalid or unavailable. You can close this page.',
            ],
        };
        $nonce = $this->tokens->generate();
        $response = new HtmlResponse($this->resultPage($heading, $message, $nonce), $problem->status);

        return $this->secure($this->clearCookies($response, $path), $nonce);
    }

    /** @return array{LoginApplicationKind, string, string} */
    private function routeContext(ServerRequestInterface $request): array
    {
        $applicationValue = mb_strtolower(trim((string) $request->getAttribute('applicationKind', '')));
        $application = LoginApplicationKind::tryFrom($applicationValue);
        $requestId = mb_strtolower(trim((string) $request->getAttribute('requestId', '')));
        if (
            $application === null
            || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $requestId,
            ) !== 1
        ) {
            throw new Problem(404, 'Login request unavailable', 'This login request is invalid or unavailable.');
        }

        return [
            $application,
            $requestId,
            '/login-links/' . $application->value . '/' . rawurlencode($requestId),
        ];
    }

    private function assertBrowserOrigin(ServerRequestInterface $request, bool $required): void
    {
        $origin = mb_strtolower(trim($request->getHeaderLine('Origin')));
        $fetchSite = mb_strtolower(trim($request->getHeaderLine('Sec-Fetch-Site')));
        if (
            ($required && ($origin === '' || ! hash_equals($this->publicOrigin, $origin)))
            || (! $required && $origin !== '' && ! hash_equals($this->publicOrigin, $origin))
            || ($fetchSite !== '' && $fetchSite !== 'same-origin')
        ) {
            throw new Problem(403, 'Approval rejected', 'The browser confirmation origin is invalid.');
        }
    }

    private function cookie(string $name, string $value, string $path, int $maxAge): string
    {
        $secure = $this->cookieSecure ? '; Secure' : '';
        $expires = $maxAge === 0 ? '; Expires=Thu, 01 Jan 1970 00:00:00 GMT' : '';

        return $name . '=' . rawurlencode($value) . '; Path=' . $path . $secure
            . '; HttpOnly; SameSite=Strict; Priority=High; Max-Age=' . $maxAge . $expires;
    }

    private function clearCookies(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response
            ->withAddedHeader('Set-Cookie', $this->cookie(self::APPROVAL_COOKIE, 'deleted', $path, 0))
            ->withAddedHeader('Set-Cookie', $this->cookie(self::CSRF_COOKIE, 'deleted', $path, 0));
    }

    private function resultPage(string $heading, string $message, string $nonce): string
    {
        $escapedHeading = $this->escape($heading);
        $escapedMessage = $this->escape($message);
        $content = <<<HTML
            <p class="eyebrow">Login link</p>
            <h1 id="login-title">{$escapedHeading}</h1>
            <p>{$escapedMessage}</p>
            HTML;

        return $this->page($heading, $content, $nonce);
    }

    private function page(string $title, string $content, string $nonce, string $script = ''): string
    {
        $escapedNonce = $this->escape($nonce);
        $escapedTitle = $this->escape($title);

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="color-scheme" content="light">
                <title>{$escapedTitle}</title>
                <style nonce="{$escapedNonce}">
                    :root { color-scheme: light; font-family: system-ui, sans-serif; }
                    * { box-sizing: border-box; }
                    body { min-height: 100vh; margin: 0; color: #102714; background: #fbf8ec; }
                    main { display: grid; min-height: 100vh; place-items: center; padding: 1.25rem; }
                    section {
                        width: min(100%, 42rem);
                        padding: clamp(1.5rem, 5vw, 3.5rem);
                        border-radius: 1.25rem;
                        background: #fffdf7;
                        box-shadow: 0 .75rem 2rem rgb(20 85 31 / 12%);
                    }
                    .eyebrow {
                        margin: 0 0 .5rem;
                        color: #2f8a2a;
                        font-weight: 700;
                        letter-spacing: .06em;
                        text-transform: uppercase;
                    }
                    h1 { margin: 0 0 1.25rem; color: #14551f; font-size: clamp(2rem, 7vw, 3.5rem); line-height: 1.05; }
                    p { line-height: 1.6; }
                    .warning { padding: .8rem 1rem; border-left: .25rem solid #e76f00; background: #fff7e6; }
                    .muted, dt { color: #726e62; }
                    dl { display: grid; gap: .75rem; margin: 1.5rem 0; }
                    dl div { display: grid; grid-template-columns: minmax(7rem, 1fr) 2fr; gap: 1rem; }
                    dt { font-weight: 600; }
                    dd { margin: 0; overflow-wrap: anywhere; }
                    .actions { display: flex; flex-wrap: wrap; gap: .75rem; margin: 1.5rem 0; }
                    form { margin: 0; }
                    .button {
                        min-height: 2.75rem;
                        padding: .75rem 1rem;
                        border: .125rem solid #14551f;
                        border-radius: .875rem;
                        font: inherit;
                        font-weight: 700;
                        cursor: pointer;
                    }
                    .approve { color: #fff; background: #2f8a2a; }
                    .deny { color: #14551f; background: #fff; }
                    :focus-visible { outline: .2rem solid #0b63ce; outline-offset: .2rem; }
                    @media (max-width: 34rem) { dl div { grid-template-columns: 1fr; gap: .1rem; } }
                </style>
            </head>
            <body>
            <main>
                <section aria-labelledby="login-title">
                    {$content}
                </section>
            </main>
            {$script}
            </body>
            </html>
            HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function secure(
        ResponseInterface $response,
        ?string $nonce = null,
        bool $allowScript = false,
    ): ResponseInterface
    {
        $stylePolicy = $nonce === null ? "style-src 'none';" : "style-src 'nonce-" . $nonce . "';";
        $scriptPolicy = $allowScript && $nonce !== null
            ? " script-src 'nonce-" . $nonce . "';"
            : " script-src 'none';";

        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; " . $stylePolicy . $scriptPolicy
                . " form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'",
            );
    }
}
