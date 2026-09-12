<?php

declare(strict_types=1);

namespace Providentia\Catalog\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Catalog\Application\CatalogMaintenanceService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CatalogMaintenanceHandler implements RequestHandlerInterface
{
    public function __construct(private CatalogMaintenanceService $service) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (!($identity instanceof AuthenticatedIdentity)) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }
        $type = (string) $request->getAttribute('entityType', '');
        if ($request->getMethod() === 'GET') {
            return new JsonResponse([
                'data' => $this->service->list(
                    $identity,
                    $type,
                    (int) ($request->getQueryParams()['offset'] ?? 0),
                ),
            ]);
        }
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        return new JsonResponse(
            $this->service->save($identity, $type, (string) $request->getAttribute('entityId', ''), $body),
        );
    }
}
