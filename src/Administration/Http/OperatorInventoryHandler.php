<?php

declare(strict_types=1);

namespace Providentia\Administration\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Administration\Application\OperatorInventoryService;
use Providentia\SharedKernel\Http\RequestIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OperatorInventoryHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly OperatorInventoryService $inventory,
        private readonly string $action,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = RequestIdentity::require($request);
        $homeId = (string) $request->getAttribute('homeId', '');
        /** @var array<string, mixed> $input */
        $input = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $result = match ($this->action) {
            'product-create' => $this->inventory->createProduct($identity, $homeId, $input),
            'product-update' => $this->inventory->updateProduct(
                $identity,
                $homeId,
                (string) $request->getAttribute('homeProductId', ''),
                $input,
            ),
            'category-create' => $this->inventory->createCategory($identity, $homeId, $input),
            'category-update' => $this->inventory->updateCategory(
                $identity,
                $homeId,
                (string) $request->getAttribute('categoryId', ''),
                $input,
            ),
            'location-create' => $this->inventory->createLocation($identity, $homeId, $input),
            'location-update' => $this->inventory->updateLocation(
                $identity,
                $homeId,
                (string) $request->getAttribute('locationId', ''),
                $input,
            ),
            'store-create' => $this->inventory->createStore($identity, $homeId, $input),
            'store-update' => $this->inventory->updateStore(
                $identity,
                $homeId,
                (string) $request->getAttribute('storeId', ''),
                $input,
            ),
            default => throw new \LogicException('Unsupported operator inventory action.'),
        };
        return new JsonResponse($result, str_ends_with($this->action, '-create') ? 201 : 200, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
