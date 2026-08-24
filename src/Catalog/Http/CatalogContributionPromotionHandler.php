<?php

declare(strict_types=1);

namespace Providentia\Catalog\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Catalog\Application\CatalogContributionPromotionService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CatalogContributionPromotionHandler implements RequestHandlerInterface
{
    public function __construct(private CatalogContributionPromotionService $promotions)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $fields = array_keys($body);
        sort($fields);
        if ($fields !== ['expectedRevision', 'publishedCategoryId']) {
            throw new HttpProblem(422, 'Validation failed', 'The request fields do not match the operation.');
        }

        return new JsonResponse($this->promotions->put(
            $identity,
            (string) $request->getAttribute('contributionId', ''),
            (string) ($body['publishedCategoryId'] ?? ''),
            (int) ($body['expectedRevision'] ?? 0),
        ));
    }
}
