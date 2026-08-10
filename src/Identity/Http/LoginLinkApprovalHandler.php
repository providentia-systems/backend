<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Template\TemplateRendererInterface;
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
        private readonly TemplateRendererInterface $renderer,
        private readonly SecureTokenGenerator $tokens,
        private readonly string $action,
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

            return $this->problemResult(
                (string) $request->getAttribute('requestId', ''),
                $problem,
            );
        }
    }

    private function launch(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = (string) $request->getAttribute('requestId', '');
        $nonce = $this->tokens->generate();

        return $this->secure(new HtmlResponse($this->renderer->render(
            'public-site::login-link-launch',
            ['requestId' => $requestId, 'nonce' => $nonce],
        )), $nonce);
    }

    private function capture(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = (string) $request->getAttribute('requestId', '');
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $approvalToken = (string) ($body['approval'] ?? '');
        $path = '/login-links/' . rawurlencode($requestId);

        $secure = $this->cookieSecure ? '; Secure' : '';
        $validToken = preg_match('/^[A-Za-z0-9_-]{40,128}$/', $approvalToken) === 1;
        $cookieValue = $validToken ? rawurlencode($approvalToken) : 'deleted';
        $maxAge = $validToken ? $this->cookieTtlSeconds : 0;

        return $this->secure((new EmptyResponse(303))
            ->withHeader('Location', $path . '/review')
            ->withAddedHeader('Set-Cookie', self::APPROVAL_COOKIE . '=' . $cookieValue
                . '; Path=' . $path . $secure . '; HttpOnly; SameSite=Strict; Max-Age='
                . $maxAge));
    }

    private function review(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = (string) $request->getAttribute('requestId', '');
        $approvalToken = (string) ($request->getCookieParams()[self::APPROVAL_COOKIE] ?? '');
        $details = $this->loginLinks->review($requestId, $approvalToken);
        $csrf = $this->tokens->generate();
        $path = '/login-links/' . rawurlencode($requestId);

        $secure = $this->cookieSecure ? '; Secure' : '';

        return $this->secure((new HtmlResponse($this->renderer->render('public-site::login-link-review', [
            'requestId' => $requestId,
            'deviceName' => $details['deviceName'],
            'platform' => $details['platform'],
            'createdAt' => $details['createdAt'],
            'expiresAt' => $details['expiresAt'],
            'csrf' => $csrf,
        ])))
            ->withAddedHeader('Set-Cookie', self::CSRF_COOKIE . '=' . rawurlencode($csrf)
                . '; Path=' . $path . $secure . '; HttpOnly; SameSite=Strict; Max-Age='
                . $this->cookieTtlSeconds));
    }

    private function decision(ServerRequestInterface $request, bool $approve): ResponseInterface
    {
        $requestId = (string) $request->getAttribute('requestId', '');
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $cookies = $request->getCookieParams();
        $submittedCsrf = (string) ($body['csrf'] ?? '');
        $cookieCsrf = (string) ($cookies[self::CSRF_COOKIE] ?? '');
        if ($submittedCsrf === '' || $cookieCsrf === '' || ! hash_equals($cookieCsrf, $submittedCsrf)) {
            throw new Problem(403, 'Approval rejected', 'The browser confirmation expired or is invalid.');
        }
        $approvalToken = (string) ($cookies[self::APPROVAL_COOKIE] ?? '');
        $result = $approve
            ? $this->loginLinks->approve($requestId, $approvalToken)
            : $this->loginLinks->deny($requestId, $approvalToken);
        $path = '/login-links/' . rawurlencode($requestId);
        $secure = $this->cookieSecure ? '; Secure' : '';
        $expired = '; Path=' . $path . $secure
            . '; HttpOnly; SameSite=Strict; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';

        return $this->secure((new HtmlResponse($this->renderer->render('public-site::login-link-result', [
            'heading' => $approve ? 'Login approved' : 'Login denied',
            'message' => $approve
                ? 'You can close this page and return to Providentia.'
                : 'No client session was created. You can close this page.',
        ])))
            ->withAddedHeader('Set-Cookie', self::APPROVAL_COOKIE . '=deleted' . $expired)
            ->withAddedHeader('Set-Cookie', self::CSRF_COOKIE . '=deleted' . $expired));
    }

    private function problemResult(string $requestId, Problem $problem): ResponseInterface
    {
        $path = '/login-links/' . rawurlencode($requestId);
        $secure = $this->cookieSecure ? '; Secure' : '';
        $expired = '; Path=' . $path . $secure
            . '; HttpOnly; SameSite=Strict; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        [$heading, $message] = match ($problem->status) {
            410 => ['Login link expired', 'Request a new login link in Providentia. You can close this page.'],
            409 => ['Login link already handled', 'Return to the requesting client or request a new login link.'],
            default => [
                'Login link unavailable',
                'This confirmation is invalid or unavailable. You can close this page.',
            ],
        };

        return $this->secure((new HtmlResponse($this->renderer->render('public-site::login-link-result', [
            'heading' => $heading,
            'message' => $message,
        ]), $problem->status))
            ->withAddedHeader('Set-Cookie', self::APPROVAL_COOKIE . '=deleted' . $expired)
            ->withAddedHeader('Set-Cookie', self::CSRF_COOKIE . '=deleted' . $expired));
    }

    private function secure(ResponseInterface $response, ?string $scriptNonce = null): ResponseInterface
    {
        $scriptPolicy = $scriptNonce === null ? '' : " script-src 'nonce-" . $scriptNonce . "';";

        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; style-src 'self';" . $scriptPolicy
                . " form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            );
    }
}
