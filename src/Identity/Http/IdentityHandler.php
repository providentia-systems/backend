<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AuthenticationService;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class IdentityHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly string $action,
        private readonly bool $cookieSecure = true,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return match ($this->action) {
            'refresh' => $this->refresh($request, $body),
            'sessions' => new JsonResponse(['data' => $this->authentication->listSessions($this->identity($request))]),
            'revoke-session' => $this->revoke($request),
            'logout' => $this->logout($request),
            default => throw new \LogicException('Unknown identity action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function refresh(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $application = WebSessionContext::application($request);
        $cookieToken = (string) ($request->getCookieParams()[WebSessionContext::cookie('refresh', $application)] ?? '');
        $csrfProof = null;
        if ($application !== null && $cookieToken !== '') {
            $csrfProof = $request->getHeaderLine('X-CSRF-Token');
            $cookieCsrf = (string) ($request->getCookieParams()[WebSessionContext::cookie('csrf', $application)] ?? '');
            if ($csrfProof === '' || $cookieCsrf === '' || ! hash_equals($cookieCsrf, $csrfProof)) {
                throw new HttpProblem(403, 'CSRF validation failed', 'The CSRF credential is missing or invalid.');
            }
        }
        $tokens = $this->authentication->refresh(
            $cookieToken !== '' ? $cookieToken : (string) ($body['refreshToken'] ?? ''),
            $csrfProof,
        );

        return ($tokens['transport'] ?? 'native') === 'web'
            ? SessionResponseFactory::web($tokens, $this->cookieSecure, $application)
            : new JsonResponse($tokens);
    }

    private function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $application = WebSessionContext::application($request);
        $sessionId = (string) $request->getAttribute('sessionId', '');
        $identity = $this->identity($request);
        $this->authentication->revokeSession($identity, $sessionId);

        return $sessionId === $identity->sessionId
            ? SessionResponseFactory::cleared(cookieSecure: $this->cookieSecure, application: $application)
            : new EmptyResponse(204);
    }

    private function logout(ServerRequestInterface $request): ResponseInterface
    {
        $application = WebSessionContext::application($request);
        $authorization = $request->getHeaderLine('Authorization');
        $cookies = $request->getCookieParams();
        $accessCookie = (string) ($cookies[WebSessionContext::cookie('access', $application)] ?? '');
        $bearer = preg_match('/^Bearer ([A-Za-z0-9_-]{40,})$/', $authorization, $matches) === 1
            ? $matches[1]
            : '';
        $accessToken = $bearer !== '' ? $bearer : $accessCookie;
        if ($accessToken !== '') {
            try {
                $identity = $this->authentication->authenticate($accessToken);
                if ($bearer === '') {
                    $csrf = $request->getHeaderLine('X-CSRF-Token');
                    if (
                        $csrf === ''
                        || ! hash_equals(
                            (string) ($cookies[WebSessionContext::cookie('csrf', $application)] ?? ''),
                            $csrf,
                        )
                        || ! $this->authentication->verifyCsrf($identity, $csrf)
                    ) {
                        return SessionResponseFactory::cleared(403, $this->cookieSecure, $application);
                    }
                }
                $this->authentication->revokeSession($identity, $identity->sessionId);

                return SessionResponseFactory::cleared(cookieSecure: $this->cookieSecure, application: $application);
            } catch (Problem) {
                // A refresh proof can still revoke an access-expired session.
            }
        }
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $nativeRefresh = (string) ($body['refreshToken'] ?? '');
        if ($nativeRefresh !== '') {
            return SessionResponseFactory::cleared(
                $this->authentication->revokeSessionByRefreshToken($nativeRefresh) ? 204 : 401,
                $this->cookieSecure,
                $application,
            );
        }
        $webRefresh = (string) ($cookies[WebSessionContext::cookie('refresh', $application)] ?? '');
        if ($webRefresh !== '') {
            $csrf = $request->getHeaderLine('X-CSRF-Token');
            $cookieCsrf = (string) ($cookies[WebSessionContext::cookie('csrf', $application)] ?? '');
            if ($csrf === '' || $cookieCsrf === '' || ! hash_equals($cookieCsrf, $csrf)) {
                return SessionResponseFactory::cleared(403, $this->cookieSecure, $application);
            }

            return SessionResponseFactory::cleared(
                $this->authentication->revokeSessionByRefreshProof($webRefresh, $csrf) ? 204 : 401,
                $this->cookieSecure,
                $application,
            );
        }

        return SessionResponseFactory::cleared(401, $this->cookieSecure, $application);
    }

    private function identity(ServerRequestInterface $request): AuthenticatedIdentity
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }

        return $identity;
    }
}
