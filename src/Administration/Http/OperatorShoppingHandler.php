<?php

declare(strict_types=1);

namespace Providentia\Administration\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Administration\Application\OperatorShoppingService;
use Providentia\SharedKernel\Http\RequestIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OperatorShoppingHandler implements RequestHandlerInterface
{
    public function __construct(private readonly OperatorShoppingService $shopping, private readonly string $action)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestIdentity::require($request);
        $homeId = (string) $request->getAttribute('homeId', '');
        /** @var array<string, mixed> $input */
        $input = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $creating = str_ends_with($this->action, '-create');
        $listId = (string) $request->getAttribute('listId', '');
        $lineId = (string) $request->getAttribute('lineId', '');
        $id = is_string($input['id'] ?? null) ? $input['id'] : '';
        $result = match ($this->action) {
            'list-create', 'list-update' => $this->shopping->saveList(
                $actor,
                $homeId,
                $creating ? $id : $listId,
                $input,
                $creating,
            ),
            'line-create', 'line-update' => $this->shopping->saveLine(
                $actor,
                $homeId,
                $listId,
                $creating ? $id : $lineId,
                $input,
                $creating,
            ),
            'line-check' => $this->shopping->checkLine($actor, $homeId, $listId, $lineId, $input),
            default => throw new \LogicException('Unsupported operator shopping action.'),
        };
        return new JsonResponse($result, $creating ? 201 : 200, ['Cache-Control' => 'no-store']);
    }
}
