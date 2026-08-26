<?php

declare(strict_types=1);

namespace Providentia\Home\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Home\Application\HomeService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Application\Problem;
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
            'update' => $this->update($identity, $homeId, $body),
            'memberships' => new JsonResponse(['data' => $this->homes->memberships($identity, $homeId)]),
            'permission-policies' => new JsonResponse([
                'data' => $this->homes->permissionPolicies($identity, $homeId),
            ]),
            'configure-permissions' => new JsonResponse($this->homes->configureRolePermissions(
                $identity,
                $homeId,
                (string) $request->getAttribute('role', ''),
                $this->stringList($body['permissions'] ?? null),
                (int) ($body['expectedRevision'] ?? -1),
            )),
            'invitations' => new JsonResponse(['data' => $this->homes->invitations($identity, $homeId)]),
            'invite' => $this->invite($identity, $homeId, $body),
            'revoke-invitation' => $this->revokeInvitation($identity, $homeId, $request, $body),
            'accept-invitation' => new JsonResponse(
                $this->homes->acceptInvitation($identity, (string) ($body['token'] ?? '')),
            ),
            'pending-invitations' => new JsonResponse(['data' => $this->homes->pendingInvitations($identity)]),
            'accept-invitation-by-id' => new JsonResponse($this->homes->acceptInvitationById(
                $identity,
                (string) $request->getAttribute('invitationId', ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'switch' => new JsonResponse($this->homes->switch($identity, $homeId)),
            'change-role' => $this->changeRole($identity, $homeId, $request, $body),
            'remove-member' => $this->removeMember($identity, $homeId, $request),
            'transfer-ownership' => $this->transferOwnership($identity, $homeId, $body),
            'ownership-transfers' => new JsonResponse([
                'data' => $this->homes->ownershipTransfers($identity, $homeId),
            ]),
            'propose-ownership-transfer' => new JsonResponse($this->homes->proposeOwnershipTransfer(
                $identity,
                $homeId,
                (string) ($body['targetUserId'] ?? ''),
                (int) ($body['expectedTargetRevision'] ?? 0),
                (string) ($body['stepUpToken'] ?? ''),
            ), 201),
            'accept-ownership-transfer' => $this->ownershipTransition(
                $identity,
                $homeId,
                $request,
                $body,
                'accept',
            ),
            'reject-ownership-transfer' => $this->ownershipTransition(
                $identity,
                $homeId,
                $request,
                $body,
                'reject',
            ),
            'revoke-ownership-transfer' => $this->ownershipTransition(
                $identity,
                $homeId,
                $request,
                $body,
                'revoke',
            ),
            'leave' => $this->leave($identity, $homeId),
            default => throw new \LogicException('Unknown home action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function update(
        AuthenticatedIdentity $identity,
        string $homeId,
        array $body,
    ): ResponseInterface {
        $allowed = ['expectedRevision', 'name', 'locale', 'currency', 'timezone'];
        foreach (array_keys($body) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw new Problem(422, 'Validation failed', 'Unknown home setting: ' . $field . '.');
            }
        }
        $mutable = array_intersect(['name', 'locale', 'currency', 'timezone'], array_keys($body));
        if ($mutable === []) {
            throw new Problem(422, 'Validation failed', 'At least one home setting must be supplied.');
        }
        if (! isset($body['expectedRevision']) || (int) $body['expectedRevision'] < 1) {
            throw new Problem(422, 'Validation failed', 'A positive expected revision is required.');
        }
        $current = $this->homes->get($identity, $homeId);

        return new JsonResponse($this->homes->update(
            $identity,
            $homeId,
            array_key_exists('name', $body) ? (string) $body['name'] : (string) $current['name'],
            array_key_exists('locale', $body)
                ? (string) $body['locale']
                : (string) $current['defaultLocale'],
            array_key_exists('currency', $body)
                ? (string) $body['currency']
                : (string) $current['defaultCurrency'],
            array_key_exists('timezone', $body)
                ? (string) $body['timezone']
                : (string) $current['defaultTimezone'],
            (int) ($body['expectedRevision'] ?? 0),
        ));
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
            'revision' => $invitation['revision'],
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

    /** @param array<string, mixed> $body */
    private function revokeInvitation(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->homes->revokeInvitation(
            $identity,
            $homeId,
            (string) $request->getAttribute('invitationId', ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }

    private function removeMember(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $query = $request->getQueryParams();
        if (! isset($query['expectedRevision']) || ! ctype_digit((string) $query['expectedRevision'])) {
            throw new Problem(
                422,
                'Validation failed',
                'expectedRevision is required to remove a membership.',
            );
        }
        $this->homes->removeMember(
            $identity,
            $homeId,
            (string) $request->getAttribute('userId', ''),
            (int) $query['expectedRevision'],
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
            (string) ($body['stepUpToken'] ?? ''),
        );

        return new EmptyResponse(202);
    }

    /** @param array<string, mixed> $body */
    private function ownershipTransition(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
        string $transition,
    ): ResponseInterface {
        $transferId = (string) $request->getAttribute('transferId', '');
        $expectedRevision = (int) ($body['expectedRevision'] ?? 0);
        match ($transition) {
            'accept' => $this->homes->acceptOwnershipTransfer(
                $identity,
                $homeId,
                $transferId,
                $expectedRevision,
            ),
            'reject' => $this->homes->rejectOwnershipTransfer(
                $identity,
                $homeId,
                $transferId,
                $expectedRevision,
            ),
            'revoke' => $this->homes->revokeOwnershipTransfer(
                $identity,
                $homeId,
                $transferId,
                $expectedRevision,
            ),
            default => throw new \LogicException('Unknown ownership-transfer transition.'),
        };

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

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
