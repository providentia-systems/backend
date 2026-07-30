<?php

declare(strict_types=1);

namespace Providentia\Catalog\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Catalog\Application\CatalogQueryService;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CatalogProductHandler implements RequestHandlerInterface
{
    public function __construct(private CatalogQueryService $catalog)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $product = $this->catalog->product((string) $request->getAttribute('productId', ''));
        if ($product === null) {
            throw new HttpProblem(404, 'Not found', 'The requested resource is unavailable.');
        }

        return new JsonResponse($product);
    }
}
