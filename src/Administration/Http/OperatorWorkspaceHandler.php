<?php

declare(strict_types=1);

namespace Providentia\Administration\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Administration\Application\OperatorWorkspaceService;
use Providentia\SharedKernel\Http\RequestIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OperatorWorkspaceHandler implements RequestHandlerInterface
{
    public function __construct(private readonly OperatorWorkspaceService $workspace, private readonly string $action)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = RequestIdentity::require($request);
        $query = $request->getQueryParams();
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $homeId = (string) $request->getAttribute('homeId', '');
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        if ($this->action === 'review-administrator') {
            $this->workspace->reviewAdministrator($identity, (string) $request->getAttribute('userId', ''), $body);
            return new JsonResponse(['changed' => true]);
        }
        return new JsonResponse(match ($this->action) {
            'homes' => ['data' => $this->workspace->homes($identity, (string) ($query['search'] ?? ''), $offset)],
            'home' => $this->workspace->home($identity, $homeId),
            'records' => ['data' => $this->workspace->records($identity, $homeId, (string) $request->getAttribute('collection', ''), $offset)],
            'administrators' => ['data' => $this->workspace->administrators($identity)],
            'audit' => ['data' => $this->workspace->audit($identity, $offset)],
            default => throw new \LogicException('Unknown operator workspace action.'),
        }, 200, ['Cache-Control' => 'no-store']);
    }
}
