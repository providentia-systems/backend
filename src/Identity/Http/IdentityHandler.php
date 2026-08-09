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
        private readonly bool $exposeDevelopmentTokens,
        private readonly bool $cookieSecure = true,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return match ($this->action) {
            'register' => $this->register($body),
            'step-up-request' => $this->stepUpRequest($request, $body),
            'verify' => $this->verify($body),
            'resend-verification' => $this->resendVerification($body),
            'login' => $this->login($body),
            'refresh' => $this->refresh($request, $body),
            'request-reset' => $this->requestReset($body),
            'reset' => $this->reset($body),
            'sessions' => new JsonResponse(['data' => $this->authentication->listSessions($this->identity($request))]),
            'revoke-session' => $this->revoke($request),
            'logout' => $this->logout($request),
            default => throw new \LogicException('Unknown identity action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function stepUpRequest(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $token = $this->authentication->requestStepUp(
            $this->identity($request),
            (string) ($body['action'] ?? ''),
        );
        $response = ['accepted' => true];
        if ($token !== null && $this->exposeDevelopmentTokens) {
            $response['developmentStepUpToken'] = $token;
        }

        return new JsonResponse($response, 202);
    }

    /** @param array<string, mixed> $body */
    private function register(array $body): ResponseInterface
    {
        $result = $this->authentication->register(
            (string) ($body['email'] ?? ''),
            (string) ($body['password'] ?? ''),
            (string) ($body['displayName'] ?? ''),
            (string) ($body['locale'] ?? 'en-NA'),
            (string) ($body['timezone'] ?? 'Africa/Windhoek'),
        );
        $response = [
            'accepted' => true,
            'verificationRequired' => true,
        ];
        if ($this->exposeDevelopmentTokens && $result['verificationToken'] !== null) {
            $response['developmentVerificationToken'] = $result['verificationToken'];
        }

        return new JsonResponse($response, 202);
    }

    /** @param array<string, mixed> $body */
    private function verify(array $body): ResponseInterface
    {
        $this->authentication->verifyEmail((string) ($body['token'] ?? ''));

        return new EmptyResponse(204);
    }

    /** @param array<string, mixed> $body */
    private function resendVerification(array $body): ResponseInterface
    {
        $token = $this->authentication->resendVerification((string) ($body['email'] ?? ''));
        $response = ['accepted' => true];
        if ($token !== null && $this->exposeDevelopmentTokens) {
            $response['developmentVerificationToken'] = $token;
        }

        return new JsonResponse($response, 202);
    }

    /** @param array<string, mixed> $body */
    private function login(array $body): ResponseInterface
    {
        $tokens = $this->authentication->login(
            (string) ($body['email'] ?? ''),
            (string) ($body['password'] ?? ''),
            (string) ($body['deviceId'] ?? ''),
            (string) ($body['deviceName'] ?? ''),
            (string) ($body['platform'] ?? ''),
            (string) ($body['transport'] ?? 'native'),
            array_key_exists('requestedSessionIdleSeconds', $body)
                ? (int) $body['requestedSessionIdleSeconds']
                : null,
        );

        return ($tokens['transport'] ?? 'native') === 'web'
            ? SessionResponseFactory::web($tokens, $this->cookieSecure)
            : new JsonResponse($tokens);
    }

    /** @param array<string, mixed> $body */
    private function refresh(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $cookieToken = (string) ($request->getCookieParams()['providentia_refresh'] ?? '');
        $tokens = $this->authentication->refresh(
            $cookieToken !== '' ? $cookieToken : (string) ($body['refreshToken'] ?? ''),
        );

        return ($tokens['transport'] ?? 'native') === 'web'
            ? SessionResponseFactory::web($tokens, $this->cookieSecure)
            : new JsonResponse($tokens);
    }

    /** @param array<string, mixed> $body */
    private function requestReset(array $body): ResponseInterface
    {
        $token = $this->authentication->requestPasswordReset((string) ($body['email'] ?? ''));
        $response = ['accepted' => true];
        if ($token !== null && $this->exposeDevelopmentTokens) {
            $response['developmentResetToken'] = $token;
        }

        return new JsonResponse($response, 202);
    }

    /** @param array<string, mixed> $body */
    private function reset(array $body): ResponseInterface
    {
        $this->authentication->resetPassword(
            (string) ($body['token'] ?? ''),
            (string) ($body['password'] ?? ''),
        );

        return new EmptyResponse(204);
    }

    private function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $sessionId = (string) $request->getAttribute('sessionId', '');
        $identity = $this->identity($request);
        $this->authentication->revokeSession($identity, $sessionId);

        return $sessionId === $identity->sessionId
            ? SessionResponseFactory::cleared(cookieSecure: $this->cookieSecure)
            : new EmptyResponse(204);
    }

    private function logout(ServerRequestInterface $request): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');
        $cookies = $request->getCookieParams();
        $accessCookie = (string) ($cookies['providentia_access'] ?? '');
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
                        || ! hash_equals((string) ($cookies['providentia_csrf'] ?? ''), $csrf)
                        || ! $this->authentication->verifyCsrf($identity, $csrf)
                    ) {
                        return SessionResponseFactory::cleared(403, $this->cookieSecure);
                    }
                }
                $this->authentication->revokeSession($identity, $identity->sessionId);

                return SessionResponseFactory::cleared(cookieSecure: $this->cookieSecure);
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
            );
        }
        $webRefresh = (string) ($cookies['providentia_refresh'] ?? '');
        if ($webRefresh !== '') {
            $csrf = $request->getHeaderLine('X-CSRF-Token');
            $cookieCsrf = (string) ($cookies['providentia_csrf'] ?? '');
            if ($csrf === '' || $cookieCsrf === '' || ! hash_equals($cookieCsrf, $csrf)) {
                return SessionResponseFactory::cleared(403, $this->cookieSecure);
            }

            return SessionResponseFactory::cleared(
                $this->authentication->revokeSessionByRefreshProof($webRefresh, $csrf) ? 204 : 401,
                $this->cookieSecure,
            );
        }

        return SessionResponseFactory::cleared(401, $this->cookieSecure);
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
