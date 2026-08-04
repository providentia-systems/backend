<?php

declare(strict_types=1);

namespace Providentia\Catalog\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Catalog\Application\CatalogImportService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CatalogImportHandler implements RequestHandlerInterface
{
    public function __construct(
        private CatalogImportService $imports,
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
        $homeId = (string) $request->getAttribute('homeId', '');
        $batchId = (string) $request->getAttribute('importId', '');

        return match ($this->action) {
            'stage' => $this->stage($identity, $homeId, $request, $body),
            'get' => new JsonResponse($this->imports->get($identity, $homeId, $batchId)),
            'confirm' => new JsonResponse($this->imports->confirm(
                $identity,
                $homeId,
                $batchId,
                (int) ($body['expectedRevision'] ?? 0),
                (string) ($body['confirmation'] ?? ''),
            )),
            default => throw new \LogicException('Unknown catalog import action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function stage(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $records = $body['records'] ?? [];
        $result = $this->imports->stage(
            $identity,
            $homeId,
            $request->getHeaderLine('Idempotency-Key'),
            is_array($records) && array_is_list($records) ? $records : [],
        );

        return new JsonResponse($result, ($result['replayed'] ?? false) === true ? 200 : 201);
    }
}
