<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\SharedKernel\Application\Problem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProblemDetailsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $debug,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $error) {
            $requestId = $this->requestId($request);

            if ($error instanceof Problem) {
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
            $context = [
                'event' => 'http.problem',
                'request_id' => $requestId,
                'status' => $status,
                'method' => $request->getMethod(),
                'uri_path' => $request->getUri()->getPath(),
                'error_class' => $error::class,
            ];
            if ($status >= 500) {
                $this->logger->error('HTTP request failed.', $context);
            } else {
                $this->logger->warning('HTTP request rejected.', $context);
            }

            return new JsonResponse([
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => $request->getUri()->getPath(),
                'requestId' => $requestId,
            ], $status, [
                'Content-Type' => 'application/problem+json',
                'X-Request-Id' => $requestId,
            ]);
        }
    }

    private function requestId(ServerRequestInterface $request): string
    {
        $requestId = $request->getHeaderLine('X-Request-Id');
        if (
            $requestId === ''
            || strlen($requestId) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1
        ) {
            return bin2hex(random_bytes(16));
        }

        return $requestId;
    }
}
