<?php

declare(strict_types=1);

namespace Providentia\Inventory\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Inventory\Application\InventoryService;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class InventoryHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly string $action,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $homeId = (string) $request->getAttribute('homeId', '');
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $query = $request->getQueryParams();

        return match ($this->action) {
            'categories.list' => new JsonResponse(['data' => $this->inventory->categories(
                $identity,
                $homeId,
                $this->booleanQuery($query, 'includeArchived'),
            )]),
            'categories.create' => new JsonResponse($this->inventory->createHomeCategory(
                $identity,
                $homeId,
                (string) ($body['name'] ?? ''),
            ), 201),
            'categories.update' => new JsonResponse($this->inventory->updateHomeCategory(
                $identity,
                $homeId,
                (string) $request->getAttribute('homeCategoryId', ''),
                array_key_exists('name', $body) ? (string) $body['name'] : null,
                array_key_exists('status', $body) ? (string) $body['status'] : null,
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'locations.list' => new JsonResponse(['data' => $this->inventory->locations($identity, $homeId)]),
            'locations.create' => new JsonResponse($this->inventory->createLocation(
                $identity,
                $homeId,
                (string) ($body['name'] ?? ''),
                (string) ($body['kind'] ?? ''),
            ), 201),
            'items.list' => new JsonResponse($this->inventory->itemMaster(
                $identity,
                $homeId,
                (string) ($query['q'] ?? ''),
                isset($query['categoryId']) ? (string) $query['categoryId'] : null,
                isset($query['homeCategoryId']) ? (string) $query['homeCategoryId'] : null,
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            )),
            'items.create' => new JsonResponse($this->inventory->addHomeProduct(
                $identity,
                $homeId,
                isset($body['productId']) ? (string) $body['productId'] : null,
                isset($body['packId']) ? (string) $body['packId'] : null,
                isset($body['privateName']) ? (string) $body['privateName'] : null,
                isset($body['originalPackText']) ? (string) $body['originalPackText'] : null,
                isset($body['homeCategoryId']) ? (string) $body['homeCategoryId'] : null,
            ), 201),
            'items.update' => new JsonResponse($this->inventory->updateHomeProduct(
                $identity,
                $homeId,
                (string) $request->getAttribute('homeProductId', ''),
                array_key_exists('privateName', $body),
                array_key_exists('privateName', $body) ? (string) $body['privateName'] : null,
                array_key_exists('originalPackText', $body),
                array_key_exists('originalPackText', $body) && $body['originalPackText'] !== null
                    ? (string) $body['originalPackText']
                    : null,
                array_key_exists('homeCategoryId', $body),
                array_key_exists('homeCategoryId', $body) && $body['homeCategoryId'] !== null
                    ? (string) $body['homeCategoryId']
                    : null,
                array_key_exists('status', $body) ? (string) $body['status'] : null,
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'stock.list' => new JsonResponse(['data' => $this->inventory->stock(
                $identity,
                $homeId,
                (string) ($query['q'] ?? ''),
                isset($query['categoryId']) ? (string) $query['categoryId'] : null,
                isset($query['homeCategoryId']) ? (string) $query['homeCategoryId'] : null,
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            )]),
            'balances.list' => new JsonResponse([
                'data' => $this->inventory->stock(
                    $identity,
                    $homeId,
                    (string) ($query['q'] ?? ''),
                    isset($query['categoryId']) ? (string) $query['categoryId'] : null,
                    isset($query['homeCategoryId']) ? (string) $query['homeCategoryId'] : null,
                    (int) ($query['limit'] ?? 50),
                    (int) ($query['offset'] ?? 0),
                ),
                'quantityType' => 'factual-ledger-balance',
            ]),
            'adjustments.create' => new JsonResponse($this->inventory->manualAdjustment(
                $identity,
                $homeId,
                (string) ($body['homeProductId'] ?? ''),
                (string) ($body['quantityDelta'] ?? ''),
                (string) ($body['reason'] ?? ''),
                $this->idempotencyKey($request, $body),
            ), 201),
            'movements.list' => new JsonResponse(['data' => $this->inventory->movements(
                $identity,
                $homeId,
                isset($query['homeProductId']) ? (string) $query['homeProductId'] : null,
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            )]),
            'counts.list' => new JsonResponse(['data' => $this->inventory->countSessions(
                $identity,
                $homeId,
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            )]),
            'counts.create' => new JsonResponse($this->inventory->startCount(
                $identity,
                $homeId,
                isset($body['locationId']) ? (string) $body['locationId'] : null,
                (string) ($body['notes'] ?? ''),
                (bool) ($body['scopeComplete'] ?? false),
                (string) ($body['reliability'] ?? 'unassessed'),
            ), 201),
            'counts.get' => new JsonResponse($this->inventory->countSession(
                $identity,
                $homeId,
                (string) $request->getAttribute('sessionId', ''),
            )),
            'counts.line' => new JsonResponse($this->inventory->recordCount(
                $identity,
                $homeId,
                (string) $request->getAttribute('sessionId', ''),
                (string) $request->getAttribute('lineId', ''),
                (string) ($body['homeProductId'] ?? ''),
                (string) ($body['quantity'] ?? ''),
                isset($body['confidence']) ? (string) $body['confidence'] : null,
                (string) ($body['source'] ?? 'manual'),
                (string) ($body['notes'] ?? ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'counts.close' => new JsonResponse($this->inventory->closeCount(
                $identity,
                $homeId,
                (string) $request->getAttribute('sessionId', ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'counts.cancel' => new JsonResponse($this->inventory->cancelCount(
                $identity,
                $homeId,
                (string) $request->getAttribute('sessionId', ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'balances.rebuild' => new JsonResponse($this->inventory->rebuild($identity, $homeId)),
            default => throw new \LogicException('Unknown inventory action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function idempotencyKey(ServerRequestInterface $request, array $body): string
    {
        $key = trim($request->getHeaderLine('Idempotency-Key'));

        return $key === '' ? (string) ($body['operationId'] ?? '') : $key;
    }

    /** @param array<string, mixed> $query */
    private function booleanQuery(array $query, string $field): bool
    {
        $value = $query[$field] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function identity(ServerRequestInterface $request): AuthenticatedIdentity
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }

        return $identity;
    }
}
