<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\AuthenticationService;
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
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return match ($this->action) {
            'register' => $this->register($body),
            'magic-link-request' => $this->magicLinkRequest($body),
            'magic-link-exchange' => $this->magicLinkExchange($body),
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
    private function magicLinkRequest(array $body): ResponseInterface
    {
        $token = $this->authentication->requestMagicLink(
            (string) ($body['email'] ?? ''),
            (string) ($body['displayName'] ?? ''),
            (string) ($body['locale'] ?? 'en-NA'),
            (string) ($body['timezone'] ?? 'Africa/Windhoek'),
        );
        $response = ['accepted' => true];
        if ($token !== null && $this->exposeDevelopmentTokens) {
            $response['developmentMagicLinkToken'] = $token;
        }

        return new JsonResponse($response, 202);
    }

    /** @param array<string, mixed> $body */
    private function magicLinkExchange(array $body): ResponseInterface
    {
        $tokens = $this->authentication->exchangeMagicLink(
            (string) ($body['token'] ?? ''),
            (string) ($body['deviceId'] ?? ''),
            (string) ($body['deviceName'] ?? ''),
            (string) ($body['platform'] ?? ''),
        );

        return ($body['transport'] ?? 'native') === 'web'
            ? $this->webSessionResponse($tokens)
            : new JsonResponse($tokens);
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
        );

        return ($body['transport'] ?? 'native') === 'web'
            ? $this->webSessionResponse($tokens)
            : new JsonResponse($tokens);
    }

    /** @param array<string, mixed> $body */
    private function refresh(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $cookieToken = (string) ($request->getCookieParams()['providentia_refresh'] ?? '');
        $tokens = $this->authentication->refresh(
            $cookieToken !== '' ? $cookieToken : (string) ($body['refreshToken'] ?? ''),
        );

        return $cookieToken !== ''
            ? $this->webSessionResponse($tokens)
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
        $this->authentication->revokeSession($this->identity($request), $sessionId);

        return new EmptyResponse(204);
    }

    private function logout(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $this->authentication->revokeSession($identity, $identity->sessionId);
        $expired = '=deleted; Path=/; Secure; HttpOnly; SameSite=Strict; Max-Age=0';

        return (new EmptyResponse(204))
            ->withAddedHeader('Set-Cookie', 'providentia_access' . $expired)
            ->withAddedHeader('Set-Cookie', 'providentia_refresh' . $expired)
            ->withAddedHeader(
                'Set-Cookie',
                'providentia_csrf=deleted; Path=/; Secure; SameSite=Strict; Max-Age=0',
            );
    }

    private function identity(ServerRequestInterface $request): AuthenticatedIdentity
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }

        return $identity;
    }

    /**
     * @param array{
     *     accessToken: string,
     *     refreshToken: string,
     *     csrfToken: string,
     *     accessExpiresAt: string,
     *     sessionId: string,
     *     deviceId: string,
     *     userId: string
     * } $tokens
     */
    private function webSessionResponse(array $tokens): ResponseInterface
    {
        $secure = '; Path=/; Secure; SameSite=Strict';
        $response = new JsonResponse([
            'sessionId' => $tokens['sessionId'],
            'deviceId' => $tokens['deviceId'],
            'userId' => $tokens['userId'],
            'accessExpiresAt' => $tokens['accessExpiresAt'],
            'csrfToken' => $tokens['csrfToken'],
            'transport' => 'secure-cookie',
        ]);

        return $response
            ->withAddedHeader('Set-Cookie', 'providentia_access=' . $tokens['accessToken'] . $secure . '; HttpOnly')
            ->withAddedHeader('Set-Cookie', 'providentia_refresh=' . $tokens['refreshToken'] . $secure . '; HttpOnly')
            ->withAddedHeader('Set-Cookie', 'providentia_csrf=' . $tokens['csrfToken'] . $secure);
    }
}
