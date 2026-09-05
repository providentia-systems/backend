<?php

declare(strict_types=1);

namespace Providentia\Catalog\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Catalog\Application\CatalogGovernanceService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CatalogGovernanceHandler implements RequestHandlerInterface
{
    public function __construct(
        private CatalogGovernanceService $catalog,
        private string $action,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return match ($this->action) {
            'proposals.submit' => new JsonResponse($this->catalog->submit(
                $identity,
                (string) ($body['type'] ?? ''),
                is_array($body['payload'] ?? null) ? $body['payload'] : [],
            ), 201),
            'workbench' => $this->workbench($identity, $request),
            'proposals.decision' => new JsonResponse($this->catalog->decideProposal(
                $identity,
                (string) $request->getAttribute('proposalId', ''),
                (string) ($body['decision'] ?? ''),
                (string) ($body['reason'] ?? ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'conflicts.keep' => $this->keepExisting($identity, $request, $body),
            'icons.put' => new JsonResponse($this->catalog->putIcon(
                $identity,
                (string) $request->getAttribute('targetType', ''),
                (string) $request->getAttribute('targetId', ''),
                $body,
            )),
            'merges.preview' => new JsonResponse($this->catalog->previewMerge(
                $identity,
                (string) ($body['survivorId'] ?? ''),
                $this->stringList($body['duplicateIds'] ?? null),
            )),
            'merges.apply' => new JsonResponse($this->catalog->applyMerge(
                $identity,
                (string) ($body['survivorId'] ?? ''),
                (int) ($body['expectedSurvivorRevision'] ?? 0),
                $this->revisions($body['duplicateRevisions'] ?? null),
                (string) ($body['reason'] ?? ''),
            ), 201),
            'merges.reverse' => new JsonResponse($this->catalog->reverseMerge(
                $identity,
                (string) $request->getAttribute('mergeId', ''),
                (int) ($body['expectedRevision'] ?? 0),
                (string) ($body['reason'] ?? ''),
            )),
            default => throw new \LogicException('Unknown catalog governance action.'),
        };
    }

    private function workbench(
        AuthenticatedIdentity $identity,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $query = $request->getQueryParams();

        return new JsonResponse([
            'data' => $this->catalog->workbench(
                $identity,
                (string) ($query['queue'] ?? 'proposals'),
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $body */
    private function keepExisting(
        AuthenticatedIdentity $identity,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->catalog->keepExisting(
            $identity,
            (string) $request->getAttribute('conflictId', ''),
            (string) ($body['reason'] ?? ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }

    /**
     * @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @return array<string, int> */
    private function revisions(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $id => $revision) {
            if (is_string($id) && is_int($revision)) {
                $result[$id] = $revision;
            }
        }

        return $result;
    }
}
