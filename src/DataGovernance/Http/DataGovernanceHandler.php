<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\DataGovernance\Application\DataGovernanceService;
use Providentia\DataGovernance\Application\DataGovernanceDownloadService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DataGovernanceHandler implements RequestHandlerInterface
{
    public function __construct(
        private DataGovernanceService $governance,
        private DataGovernanceDownloadService $downloads,
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
        $query = $request->getQueryParams();
        $homeId = (string) $request->getAttribute('homeId', '');

        return match ($this->action) {
            'account.export' => new JsonResponse($this->governance->requestAccountExport($identity), 202),
            'account.erasure' => new JsonResponse($this->governance->requestAccountErasure($identity), 202),
            'account.requests' => new JsonResponse(['data' => $this->governance->accountRequests(
                $identity,
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            )]),
            'home.export' => new JsonResponse($this->governance->requestHomeExport($identity, $homeId), 202),
            'home.erasure' => new JsonResponse($this->governance->requestHomeErasure($identity, $homeId), 202),
            'home.requests' => new JsonResponse(['data' => $this->governance->homeRequests(
                $identity,
                $homeId,
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            )]),
            'request.cancel' => $this->cancel($identity, $request, $body),
            'request.download-token' => new JsonResponse($this->downloads->issueToken(
                $identity,
                (string) $request->getAttribute('requestId', ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'request.download' => new JsonResponse(json_decode($this->downloads->download(
                $identity,
                (string) $request->getAttribute('requestId', ''),
                (string) ($body['token'] ?? ''),
            ), true, 512, JSON_THROW_ON_ERROR)),
            default => throw new \LogicException('Unknown data-governance action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function cancel(
        AuthenticatedIdentity $identity,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->governance->cancel(
            $identity,
            (string) $request->getAttribute('requestId', ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }
}
