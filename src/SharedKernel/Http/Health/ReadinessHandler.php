<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http\Health;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\SharedKernel\Application\ReadinessService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ReadinessHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ReadinessService $readiness)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->readiness->check();

        return new JsonResponse($result, $result['status'] === 'ready' ? 200 : 503);
    }
}
