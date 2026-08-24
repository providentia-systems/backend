<?php

declare(strict_types=1);

namespace Providentia\Administration\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Administration\Application\OperatorAccountService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OperatorAccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly OperatorAccountService $accounts,
        private readonly string $action,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $expectedBodyFields = match ($this->action) {
            'status' => ['expectedRevision', 'reason', 'status'],
            'role-grant', 'role-revoke' => ['expectedRevision'],
            'list', 'get' => [],
            default => null,
        };
        if ($expectedBodyFields !== null) {
            $actualBodyFields = array_keys($body);
            sort($actualBodyFields);
            if ($actualBodyFields !== $expectedBodyFields) {
                throw new HttpProblem(422, 'Validation failed', 'The request fields do not match the operation.');
            }
        }
        $query = $request->getQueryParams();
        $userId = (string) $request->getAttribute('userId', '');

        return match ($this->action) {
            'list' => new JsonResponse($this->accounts->list(
                $identity,
                (string) ($query['search'] ?? ''),
                isset($query['status']) ? (string) $query['status'] : null,
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            )),
            'get' => new JsonResponse($this->accounts->get($identity, $userId)),
            'status' => new JsonResponse($this->accounts->updateStatus(
                $identity,
                $userId,
                (string) ($body['status'] ?? ''),
                (string) ($body['reason'] ?? ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'role-grant' => new JsonResponse($this->accounts->grantRole(
                $identity,
                $userId,
                (string) $request->getAttribute('role', ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'role-revoke' => new JsonResponse($this->accounts->revokeRole(
                $identity,
                $userId,
                (string) $request->getAttribute('role', ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            default => throw new \LogicException('Unknown operator-account action.'),
        };
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
