<?php

declare(strict_types=1);

namespace Providentia\Catalog\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Catalog\Application\CatalogQueryService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CatalogSearchHandler implements RequestHandlerInterface
{
    public function __construct(private readonly CatalogQueryService $catalog)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $limit = (int) ($query['limit'] ?? 50);
        $offset = (int) ($query['offset'] ?? 0);

        return new JsonResponse([
            'data' => $this->catalog->search((string) ($query['q'] ?? ''), $limit, $offset),
            'pagination' => ['limit' => min(100, max(1, $limit)), 'offset' => max(0, $offset)],
        ]);
    }
}
