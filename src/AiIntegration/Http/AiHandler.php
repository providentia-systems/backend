<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\AiIntegration\Application\AiService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AiHandler implements RequestHandlerInterface
{
    public function __construct(
        private AiService $ai,
        private string $action,
        private int $maxImageBytes,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $homeId = (string) $request->getAttribute('homeId', '');
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return match ($this->action) {
            'settings.get' => new JsonResponse($this->ai->settings($identity, $homeId)),
            'settings.put' => new JsonResponse($this->ai->configure(
                $identity,
                $homeId,
                (string) ($body['mode'] ?? ''),
                isset($body['provider']) ? (string) $body['provider'] : null,
                isset($body['model']) ? (string) $body['model'] : null,
                (int) ($body['expectedRevision'] ?? -1),
            )),
            'credentials.put' => new JsonResponse($this->ai->putCredential(
                $identity,
                $homeId,
                (string) $request->getAttribute('providerId', ''),
                (string) ($body['credential'] ?? ''),
            )),
            'credentials.delete' => $this->removeCredential($identity, $homeId, $request),
            'profiles.list' => new JsonResponse(['items' => $this->ai->providerProfiles($identity, $homeId)]),
            'profiles.put' => new JsonResponse($this->ai->putProviderProfile(
                $identity,
                $homeId,
                ($profileId = (string) $request->getAttribute('profileId', '')) === '' ? null : $profileId,
                (string) ($body['label'] ?? ''),
                (string) ($body['provider'] ?? ''),
                (string) ($body['model'] ?? ''),
                isset($body['credential']) ? (string) $body['credential'] : null,
                (int) ($body['estimatedCostMicros'] ?? 0),
                (int) ($body['expectedRevision'] ?? 0),
            ), 201),
            'profiles.delete' => $this->removeProfile($identity, $homeId, $request, $body),
            'policy.get' => new JsonResponse($this->ai->orchestrationPolicy($identity, $homeId)),
            'policy.put' => new JsonResponse($this->ai->putOrchestrationPolicy(
                $identity,
                $homeId,
                $this->stringList($body['extractionProfileIds'] ?? null),
                isset($body['validationProfileId']) ? (string) $body['validationProfileId'] : null,
                (int) ($body['maxAttempts'] ?? 4),
                (int) ($body['maxTotalTokens'] ?? 50000),
                (int) ($body['maxEstimatedCostMicros'] ?? 1000000),
                (int) ($body['expectedRevision'] ?? 0),
            )),
            'extractions.create' => $this->extract($identity, $homeId, $request, $body),
            'extractions.create-stored' => new JsonResponse($this->ai->extractStoredMedia(
                $identity,
                $homeId,
                (string) ($body['kind'] ?? ''),
                isset($body['targetId']) ? (string) $body['targetId'] : null,
                filter_var($body['transmissionConsent'] ?? false, FILTER_VALIDATE_BOOL),
                $this->stringList($body['assetIds'] ?? null),
            ), 201),
            'extractions.get' => new JsonResponse($this->ai->extraction(
                $identity,
                $homeId,
                (string) $request->getAttribute('extractionId', ''),
            )),
            'candidates.review' => $this->reviewCandidate($identity, $homeId, $request, $body),
            'observations.review' => $this->reviewObservation($identity, $homeId, $request, $body),
            'discrepancies.review' => $this->reviewDiscrepancy($identity, $homeId, $request, $body),
            default => throw new \LogicException('Unknown AI integration action.'),
        };
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    /** @param array<string, mixed> $body */
    private function removeProfile(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->ai->removeProviderProfile(
            $identity,
            $homeId,
            (string) $request->getAttribute('profileId', ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }

    /** @param array<string, mixed> $body */
    private function reviewObservation(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->ai->reviewObservationDecision(
            $identity,
            $homeId,
            (string) $request->getAttribute('decisionId', ''),
            (string) ($body['decision'] ?? ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }

    /** @param array<string, mixed> $body */
    private function reviewDiscrepancy(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->ai->reviewDiscrepancy(
            $identity,
            $homeId,
            (string) $request->getAttribute('extractionId', ''),
            (int) $request->getAttribute('position', -1),
            (string) ($body['decision'] ?? ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }

    /** @param array<string, mixed> $body */
    private function reviewCandidate(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->ai->reviewCandidate(
            $identity,
            $homeId,
            (string) $request->getAttribute('extractionId', ''),
            (int) $request->getAttribute('position', -1),
            (string) ($body['decision'] ?? ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }

    private function removeCredential(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $this->ai->removeCredential(
            $identity,
            $homeId,
            (string) $request->getAttribute('providerId', ''),
        );

        return new EmptyResponse(204);
    }

    /** @param array<string, mixed> $body */
    private function extract(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $uploaded = $request->getUploadedFiles()['image'] ?? null;
        if (! $uploaded instanceof UploadedFileInterface || $uploaded->getError() !== UPLOAD_ERR_OK) {
            throw new HttpProblem(422, 'Invalid extraction', 'One successfully uploaded image is required.');
        }
        $size = $uploaded->getSize();
        if ($size === null || $size < 16 || $size > $this->maxImageBytes) {
            throw new HttpProblem(413, 'Image rejected', 'Image size is outside the configured limit.');
        }
        $stream = $uploaded->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $bytes = $stream->getContents();
        $additional = [];
        $uploads = $request->getUploadedFiles()['images'] ?? [];
        if ($uploads instanceof UploadedFileInterface) {
            $uploads = [$uploads];
        }
        if (is_array($uploads)) {
            foreach ($uploads as $observation) {
                if (! $observation instanceof UploadedFileInterface || $observation->getError() !== UPLOAD_ERR_OK) {
                    throw new HttpProblem(422, 'Invalid extraction', 'Every uploaded observation must succeed.');
                }
                $observationSize = $observation->getSize();
                if ($observationSize === null || $observationSize < 16 || $observationSize > $this->maxImageBytes) {
                    throw new HttpProblem(413, 'Image rejected', 'Image size is outside the configured limit.');
                }
                $observationStream = $observation->getStream();
                if ($observationStream->isSeekable()) {
                    $observationStream->rewind();
                }
                $additional[] = [
                    'mimeType' => (string) ($observation->getClientMediaType() ?? ''),
                    'bytes' => $observationStream->getContents(),
                ];
            }
        }

        return new JsonResponse($this->ai->extract(
            $identity,
            $homeId,
            (string) ($body['kind'] ?? ''),
            isset($body['targetId']) ? (string) $body['targetId'] : null,
            filter_var($body['transmissionConsent'] ?? false, FILTER_VALIDATE_BOOL),
            (string) ($uploaded->getClientMediaType() ?? ''),
            $bytes,
            $additional,
        ), 201);
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
