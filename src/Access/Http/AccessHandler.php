<?php

declare(strict_types=1);

namespace Providentia\Access\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\SharedKernel\Http\RequestIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AccessHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AccessService $access,
        private readonly string $action,
    ) {
    }

    public function handle(
        ServerRequestInterface $request,
    ): ResponseInterface {
        $identity = RequestIdentity::require($request);
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody())
            ? $request->getParsedBody()
            : [];
        $query = $request->getQueryParams();
        if ($this->action === 'assign') {
            $this->access->assign(
                $identity,
                (string) $request->getAttribute('scope', ''),
                (string) $request->getAttribute('subjectId', ''),
                (string) ($body['groupId'] ?? ''),
                (int) ($body['expectedRevision'] ?? 0),
            );
            return new JsonResponse(['changed' => true]);
        }
        if ($this->action === 'catalog') {
            $this->access->requireAdmin($identity, 'groups.manage');
            $scopes = [];
            foreach (
                [
                FeatureCatalog::ACCOUNT,
                FeatureCatalog::HOME,
                FeatureCatalog::ADMIN,
                ] as $scope
            ) {
                $scopes[] = [
                    'scope' => $scope,
                    'features' => FeatureCatalog::features($scope),
                    'limits' => FeatureCatalog::limits($scope),
                ];
            }
            return new JsonResponse(['data' => $scopes]);
        }
        return new JsonResponse(
            match ($this->action) {
                'list' => [
                    'data' => $this->access->groups(
                        $identity,
                        isset($query['scope'])
                            ? (string) $query['scope']
                            : null,
                    ),
                ],
                'create', 'update' => $this->access->saveGroup(
                    $identity,
                    $this->action === 'create'
                        ? null
                        : (string) $request->getAttribute('groupId', ''),
                    $body,
                ),
                default => throw new \LogicException('Unknown access action.'),
            },
        );
    }
}
