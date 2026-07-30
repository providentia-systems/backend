<?php

declare(strict_types=1);

namespace Providentia\Shopping\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Providentia\Shopping\Application\ShoppingService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ShoppingHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ShoppingService $shopping,
        private readonly string $action,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $homeId = (string) $request->getAttribute('homeId', '');
        $listId = (string) $request->getAttribute('listId', '');
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return match ($this->action) {
            'lists.list' => new JsonResponse(['data' => $this->shopping->lists($identity, $homeId)]),
            'lists.get' => new JsonResponse($this->shopping->shoppingList($identity, $homeId, $listId)),
            'lists.create' => new JsonResponse($this->shopping->createList(
                $identity,
                $homeId,
                (string) ($body['name'] ?? ''),
                (string) ($body['kind'] ?? 'manual'),
            ), 201),
            'lines.create' => new JsonResponse($this->shopping->addLine(
                $identity,
                $homeId,
                $listId,
                (int) ($body['expectedListRevision'] ?? 0),
                isset($body['homeProductId']) ? (string) $body['homeProductId'] : null,
                (string) ($body['description'] ?? ''),
                (string) ($body['quantityToBuy'] ?? ''),
            ), 201),
            'lines.check' => $this->check($identity, $homeId, $listId, $request, $body),
            'suggestions' => new JsonResponse([
                'data' => $this->shopping->legacySuggestions($identity, $homeId),
                'policy' => 'legacy-apr-jun-v1',
                'status' => 'provisional-phase-5-parity',
            ]),
            default => throw new \LogicException('Unknown shopping action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function check(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $listId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->shopping->setChecked(
            $identity,
            $homeId,
            $listId,
            (string) $request->getAttribute('lineId', ''),
            (bool) ($body['checked'] ?? false),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
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
