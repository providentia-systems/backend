<?php

declare(strict_types=1);

namespace Providentia\Reporting\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Reporting\Application\HomeReportService;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class HomeReportHandler implements RequestHandlerInterface
{
    public function __construct(
        private HomeReportService $reports,
        private string $type,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }
        $query = $request->getQueryParams();

        return new JsonResponse($this->reports->report(
            $identity,
            (string) $request->getAttribute('homeId', ''),
            $this->type,
            isset($query['from']) ? (string) $query['from'] : null,
            isset($query['through']) ? (string) $query['through'] : null,
        ));
    }
}
