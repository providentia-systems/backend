<?php

declare(strict_types=1);

namespace Providentia\Catalog\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Catalog\Application\CatalogContributionService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CatalogContributionHandler implements RequestHandlerInterface
{
    public function __construct(
        private CatalogContributionService $catalog,
        private string $action,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        if ($this->action === 'published.list') {
            $limit = (int) ($query['limit'] ?? 50);
            $offset = (int) ($query['offset'] ?? 0);

            return new JsonResponse([
                'data' => $this->catalog->published(
                    isset($query['type']) ? (string) $query['type'] : null,
                    $limit,
                    $offset,
                ),
                'pagination' => [
                    'limit' => min(100, max(1, $limit)),
                    'offset' => max(0, $offset),
                ],
            ]);
        }
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $homeId = (string) $request->getAttribute('homeId', '');
        match ($this->action) {
            'consent.put' => $this->requireExactFields($body, [
                'expectedRevision',
                'noticeVersion',
                'shareProductIdentity',
                'shareProductImages',
                'shareStorePrices',
            ]),
            'submit' => $this->requireExactFields($body, [
                'expectedConsentRevision',
                'payload',
                'sourceEntityId',
                'submissionId',
                'type',
            ]),
            'review.decide' => $this->requireExactFields($body, [
                'decision',
                'expectedRevision',
                'reason',
            ]),
            default => null,
        };

        return match ($this->action) {
            'consent.get' => new JsonResponse($this->catalog->consent($identity, $homeId)),
            'consent.put' => new JsonResponse($this->catalog->configureConsent(
                $identity,
                $homeId,
                ($body['shareProductIdentity'] ?? false) === true,
                ($body['shareProductImages'] ?? false) === true,
                ($body['shareStorePrices'] ?? false) === true,
                (string) ($body['noticeVersion'] ?? ''),
                (int) ($body['expectedRevision'] ?? -1),
            )),
            'submit' => $this->submit($identity, $homeId, $body),
            'list' => new JsonResponse(['data' => $this->catalog->contributions(
                $identity,
                $homeId,
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            )]),
            'review.list' => new JsonResponse(['data' => $this->catalog->reviewQueue(
                $identity,
                (string) ($query['status'] ?? 'pending'),
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            )]),
            'review.decide' => $this->decide($identity, $request, $body),
            default => throw new \LogicException('Unknown catalog contribution action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function submit(
        AuthenticatedIdentity $identity,
        string $homeId,
        array $body,
    ): ResponseInterface {
        $submission = $this->catalog->submit(
            $identity,
            $homeId,
            (string) ($body['submissionId'] ?? ''),
            (string) ($body['type'] ?? ''),
            isset($body['sourceEntityId']) ? (string) $body['sourceEntityId'] : null,
            (int) ($body['expectedConsentRevision'] ?? 0),
            is_array($body['payload'] ?? null) ? $body['payload'] : [],
        );

        return new JsonResponse($submission->contribution, $submission->created ? 201 : 200);
    }

    /** @param array<string, mixed> $body */
    private function decide(
        AuthenticatedIdentity $identity,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->catalog->decide(
            $identity,
            (string) $request->getAttribute('contributionId', ''),
            (string) ($body['decision'] ?? ''),
            (string) ($body['reason'] ?? ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }

    /** @param array<string, mixed> $body @param list<string> $expected */
    private function requireExactFields(array $body, array $expected): void
    {
        $actual = array_keys($body);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new HttpProblem(422, 'Validation failed', 'The request fields do not match the operation.');
        }
    }
}
