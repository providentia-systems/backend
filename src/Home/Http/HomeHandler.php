<?php

declare(strict_types=1);

namespace Providentia\Home\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Home\Application\HomeService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HomeHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly HomeService $homes,
        private readonly string $action,
        private readonly bool $exposeDevelopmentTokens,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $identity = $this->identity($request);
        $homeId = (string) $request->getAttribute('homeId', '');

        return match ($this->action) {
            'create' => new JsonResponse($this->homes->create(
                $identity,
                (string) ($body['name'] ?? ''),
                (string) ($body['locale'] ?? 'en-NA'),
                strtoupper((string) ($body['currency'] ?? 'NAD')),
                (string) ($body['timezone'] ?? 'Africa/Windhoek'),
            ), 201),
            'list' => new JsonResponse(['data' => $this->homes->list($identity)]),
            'get' => new JsonResponse($this->homes->get($identity, $homeId)),
            'memberships' => new JsonResponse(['data' => $this->homes->memberships($identity, $homeId)]),
            'invite' => $this->invite($identity, $homeId, $body),
            'accept-invitation' => new JsonResponse(
                $this->homes->acceptInvitation($identity, (string) ($body['token'] ?? '')),
            ),
            'switch' => new JsonResponse($this->homes->switch($identity, $homeId)),
            'change-role' => $this->changeRole($identity, $homeId, $request, $body),
            'transfer-ownership' => $this->transferOwnership($identity, $homeId, $body),
            'leave' => $this->leave($identity, $homeId),
            default => throw new \LogicException('Unknown home action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function invite(AuthenticatedIdentity $identity, string $homeId, array $body): ResponseInterface
    {
        $invitation = $this->homes->invite(
            $identity,
            $homeId,
            (string) ($body['email'] ?? ''),
            (string) ($body['role'] ?? ''),
        );
        $response = [
            'invitationId' => $invitation['invitationId'],
            'expiresAt' => $invitation['expiresAt'],
            'delivery' => 'transactional-email',
        ];
        if ($this->exposeDevelopmentTokens) {
            $response['developmentInvitationToken'] = $invitation['invitationToken'];
        }

        return new JsonResponse($response, 201);
    }

    /** @param array<string, mixed> $body */
    private function changeRole(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->homes->changeRole(
            $identity,
            $homeId,
            (string) $request->getAttribute('userId', ''),
            (string) ($body['role'] ?? ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }

    private function leave(AuthenticatedIdentity $identity, string $homeId): ResponseInterface
    {
        $this->homes->leave($identity, $homeId);

        return new EmptyResponse(204);
    }

    /** @param array<string, mixed> $body */
    private function transferOwnership(
        AuthenticatedIdentity $identity,
        string $homeId,
        array $body,
    ): ResponseInterface {
        $this->homes->transferOwnership(
            $identity,
            $homeId,
            (string) ($body['targetUserId'] ?? ''),
            (int) ($body['expectedTargetRevision'] ?? 0),
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
