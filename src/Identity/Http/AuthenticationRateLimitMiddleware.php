<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Providentia\Identity\Application\AuthenticationRateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthenticationRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthenticationRateLimiter $limiter)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $server = $request->getServerParams();
        $this->limiter->assertAllowed(
            (string) ($server['REMOTE_ADDR'] ?? 'unknown'),
            (string) ($body['email'] ?? ''),
        );

        return $handler->handle($request);
    }
}
