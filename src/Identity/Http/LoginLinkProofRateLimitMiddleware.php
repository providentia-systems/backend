<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Providentia\Identity\Application\AuthenticationRateLimiter;
use Providentia\Identity\Application\LoginLinkStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LoginLinkProofRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthenticationRateLimiter $limiter,
        private readonly LoginLinkStore $requests,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $server = $request->getServerParams();
        $requestId = mb_strtolower(trim((string) $request->getAttribute('requestId', '')));
        $knownRequestId = preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $requestId,
        ) === 1 && $this->requests->find($requestId) !== null
            ? $requestId
            : null;
        $this->limiter->assertLoginLinkProofAllowed(
            (string) ($server['REMOTE_ADDR'] ?? 'unknown'),
            $knownRequestId,
        );

        return $handler->handle($request);
    }
}
