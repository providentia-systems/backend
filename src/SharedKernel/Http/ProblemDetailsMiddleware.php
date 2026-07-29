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

            if ($error instanceof HttpProblem) {
                $status = $error->status;
                $type = $error->type;
                $title = $error->title;
                $detail = $error->getMessage();
            } else {
                $status = 500;
                $type = 'about:blank';
                $title = 'Internal Server Error';
                $detail = $this->debug
                    ? $error->getMessage()
                    : 'The request could not be completed.';
            }

            return new JsonResponse([
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => (string) $request->getUri(),
                'requestId' => $requestId,
            ], $status, [
                'Content-Type' => 'application/problem+json',
                'X-Request-Id' => $requestId,
            ]);
        }
    }
}
