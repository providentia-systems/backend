<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Providentia\Identity\Application\AuthenticationService;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BearerAuthenticationMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'providentia.identity';

    public function __construct(private readonly AuthenticationService $authentication)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');
        $cookieAuthentication = false;
        if (preg_match('/^Bearer ([A-Za-z0-9_-]{40,})$/', $authorization, $matches) === 1) {
            $token = $matches[1];
        } else {
            $token = (string) ($request->getCookieParams()['providentia_access'] ?? '');
            $cookieAuthentication = $token !== '';
        }
        if ($token === '') {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }

        $identity = $this->authentication->authenticate($token);
        if (
            $cookieAuthentication
            && in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        ) {
            $cookieCsrf = (string) ($request->getCookieParams()['providentia_csrf'] ?? '');
            $headerCsrf = $request->getHeaderLine('X-CSRF-Token');
            if (
                $cookieCsrf === ''
                || ! hash_equals($cookieCsrf, $headerCsrf)
                || ! $this->authentication->verifyCsrf($identity, $headerCsrf)
            ) {
                throw new HttpProblem(403, 'CSRF validation failed', 'The CSRF credential is missing or invalid.');
            }
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $identity));
    }
}
