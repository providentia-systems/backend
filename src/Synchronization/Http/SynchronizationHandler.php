<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Providentia\Synchronization\Application\SynchronizationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SynchronizationHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SynchronizationService $synchronization,
        private readonly string $action,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }

        if ($this->action === 'pull') {
            $cursor = $request->getQueryParams()['cursor'] ?? null;

            return new JsonResponse($this->synchronization->pull(
                $identity,
                (string) $request->getAttribute('homeId', ''),
                $request->getHeaderLine('X-Request-Id'),
                is_string($cursor) ? $cursor : null,
            ));
        }
        if ($this->action === 'bootstrap') {
            return new JsonResponse($this->synchronization->bootstrap(
                $identity,
                (string) $request->getAttribute('homeId', ''),
                $request->getHeaderLine('X-Request-Id'),
            ));
        }
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return new JsonResponse($this->synchronization->push(
            $identity,
            (string) $request->getAttribute('homeId', ''),
            $request->getHeaderLine('X-Request-Id'),
            $request->getHeaderLine('Idempotency-Key'),
            $body,
        ));
    }
}
