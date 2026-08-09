<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Application\PlatformAdministratorService;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PlatformAdministratorHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly PlatformAdministratorService $administrators,
        private readonly string $action,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $identity = $this->identity($request);

        return match ($this->action) {
            'list' => new JsonResponse(['data' => $this->administrators->list($identity)]),
            'grant' => new JsonResponse($this->administrators->grant(
                $identity,
                (string) ($body['email'] ?? ''),
            ), 201),
            'revoke' => $this->revoke($identity, $request, $body),
            default => throw new \LogicException('Unknown platform-administrator action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function revoke(
        AuthenticatedIdentity $identity,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->administrators->revoke(
            $identity,
            (string) $request->getAttribute('administratorId', ''),
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
