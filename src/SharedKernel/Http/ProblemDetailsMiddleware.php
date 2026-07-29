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

            $problem = $error instanceof HttpProblem ? $error : null;
            $status = $problem?->status ?? 500;

            return new JsonResponse([
                'type' => $problem?->type ?? 'about:blank',
                'title' => $problem?->title ?? 'Internal Server Error',
                'status' => $status,
                'detail' => $problem?->getMessage()
                    ?? ($this->debug ? $error->getMessage() : 'The request could not be completed.'),
                'instance' => (string) $request->getUri(),
                'requestId' => $requestId,
            ], $status, [
                'Content-Type' => 'application/problem+json',
                'X-Request-Id' => $requestId,
            ]);
        }
    }
}
