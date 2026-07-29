<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final class ProblemDetailsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $debug)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $error) {
            $requestId = $request->getHeaderLine('X-Request-Id');
            if ($requestId === '') {
                $requestId = bin2hex(random_bytes(16));
            }

            return new JsonResponse([
                'type' => 'about:blank',
                'title' => 'Internal Server Error',
                'status' => 500,
                'detail' => $this->debug ? $error->getMessage() : 'The request could not be completed.',
                'instance' => (string) $request->getUri(),
                'requestId' => $requestId,
            ], 500, [
                'Content-Type' => 'application/problem+json',
                'X-Request-Id' => $requestId,
            ]);
        }
    }
}
