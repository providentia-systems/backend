<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Providentia\Identity\Application\AuthenticationRateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LoginLinkProofRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthenticationRateLimiter $limiter)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $server = $request->getServerParams();
        $this->limiter->assertLoginLinkProofAllowed(
            (string) ($server['REMOTE_ADDR'] ?? 'unknown'),
        );

        return $handler->handle($request);
    }
}
