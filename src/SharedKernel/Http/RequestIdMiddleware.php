<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getHeaderLine('X-Request-Id');
        if (
            $requestId === ''
            || strlen($requestId) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1
        ) {
            $requestId = bin2hex(random_bytes(16));
        }

        $response = $handler->handle($request->withHeader('X-Request-Id', $requestId));

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
