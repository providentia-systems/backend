<?php

declare(strict_types=1);

namespace Providentia\Reporting\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Reporting\Application\DashboardService;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DashboardHandler implements RequestHandlerInterface
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }

        return new JsonResponse($this->dashboard->dashboard(
            $identity,
            (string) $request->getAttribute('homeId', ''),
        ));
    }
}
