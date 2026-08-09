<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cache-Control', 'no-store');
        if (! $response->hasHeader('Content-Security-Policy')) {
            $response = $response->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            );
        }

        return $response;
    }
}
