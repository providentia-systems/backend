<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return $handler->handle($request);
        }
        if (! in_array($origin, $this->allowedOrigins, true)) {
            throw new HttpProblem(403, 'Origin forbidden', 'This web origin is not permitted to call the API.');
        }
        $response = strtoupper($request->getMethod()) === 'OPTIONS'
            ? new EmptyResponse(204)
            : $handler->handle($request);

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader(
                'Access-Control-Allow-Headers',
                'Authorization, Content-Type, Idempotency-Key, X-CSRF-Token, X-Request-Id',
            )
            ->withHeader('Access-Control-Max-Age', '600')
            ->withHeader('Vary', 'Origin');
    }
}
