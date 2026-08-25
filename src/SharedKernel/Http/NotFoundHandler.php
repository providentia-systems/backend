<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class NotFoundHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = $request->getHeaderLine('X-Request-Id');
        if (
            $requestId === ''
            || strlen($requestId) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1
        ) {
            $requestId = bin2hex(random_bytes(16));
        }

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'The requested API resource is unavailable.',
            'instance' => $request->getUri()->getPath(),
            'requestId' => $requestId,
        ], 404, [
            'Content-Type' => 'application/problem+json',
            'X-Request-Id' => $requestId,
        ]);
    }
}
