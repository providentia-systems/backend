<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\AiIntegration\Application\AiService;
use Providentia\AiIntegration\Application\SensitiveBufferEraser;
use Providentia\AiIntegration\Application\SensitiveBufferScope;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class AiHandler implements RequestHandlerInterface
{
    private const MAX_ADDITIONAL_IMAGES = 7;

    public function __construct(
        private AiService $ai,
        private string $action,
        private int $maxImageBytes,
        private SensitiveBufferEraser $buffers,
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
                (string) ($body['ownerScope'] ?? 'private'),
                isset($body['endpoint']) ? (string) $body['endpoint'] : null,
            ), 201),
            'profiles.delete' => $this->removeProfile($identity, $homeId, $request, $body),
            'profiles.credential.delete' => new JsonResponse($this->ai->revokeProviderProfileCredential(
                $identity,
                $homeId,
                (string) $request->getAttribute('profileId', ''),
                (int) ($body['expectedRevision'] ?? 0),
            )),
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
            'extractions.create-stored' => $this->extractStored($identity, $homeId, $body),
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

    /**
     * @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    /**
     * @param array<string, mixed> $body */
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

    /**
     * @param array<string, mixed> $body */
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

    /**
     * @param array<string, mixed> $body */
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

    /**
     * @param array<string, mixed> $body */
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

    /**
     * @param array<string, mixed> $body */
    private function extract(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $uploadedFiles = $request->getUploadedFiles();
        $bytes = '';
        $additional = [];
        $sensitive = new SensitiveBufferScope($this->buffers);
        $sensitive->track($bytes);

        try {
            $this->requireExactKeys(
                $body,
                ['kind', 'targetId', 'transmissionConsent'],
                ['kind', 'transmissionConsent'],
            );
            if (! $this->isExplicitTrue($body['transmissionConsent'])) {
                throw new HttpProblem(
                    422,
                    'Transmission consent required',
                    'Confirm the selected provider and privacy mode before sending an image.',
                );
            }
            $fileKeys = array_keys($uploadedFiles);
            if (
                array_diff($fileKeys, ['image', 'images', 'images[]']) !== []
                || (isset($uploadedFiles['images']) && isset($uploadedFiles['images[]']))
            ) {
                throw new HttpProblem(422, 'Invalid extraction', 'Multipart file fields do not match the contract.');
            }
            $uploaded = $uploadedFiles['image'] ?? null;
            if (! $uploaded instanceof UploadedFileInterface || $uploaded->getError() !== UPLOAD_ERR_OK) {
                throw new HttpProblem(422, 'Invalid extraction', 'One successfully uploaded image is required.');
            }
            // PHP normalizes repeated multipart fields named `images[]` to
            // `images`. The literal key supports non-normalizing PSR adapters.
            $uploads = $uploadedFiles['images'] ?? $uploadedFiles['images[]'] ?? [];
            if ($uploads instanceof UploadedFileInterface) {
                $uploads = [$uploads];
            }
            if (! is_array($uploads) || count($uploads) > self::MAX_ADDITIONAL_IMAGES) {
                throw new HttpProblem(413, 'Too many images', 'At most eight image observations may be uploaded.');
            }
            $this->validateUpload($uploaded, 'One successfully uploaded image is required.');
            $bytes = $this->readUpload($uploaded);
            foreach ($uploads as $observation) {
                if (! $observation instanceof UploadedFileInterface) {
                    throw new HttpProblem(422, 'Invalid extraction', 'Every uploaded observation must succeed.');
                }
                $this->validateUpload($observation, 'Every uploaded observation must succeed.');
                $additional[] = [
                    'mimeType' => (string) ($observation->getClientMediaType() ?? ''),
                    'bytes' => $this->readUpload($observation),
                ];
                $position = count($additional) - 1;
                $sensitive->track($additional[$position]['bytes']);
            }

            return new JsonResponse($this->ai->extract(
                $identity,
                $homeId,
                (string) ($body['kind'] ?? ''),
                isset($body['targetId']) ? (string) $body['targetId'] : null,
                true,
                (string) ($uploaded->getClientMediaType() ?? ''),
                $bytes,
                $additional,
            ), 201);
        } finally {
            try {
                $sensitive->eraseAll();
            } finally {
                $this->closeUploads($uploadedFiles);
            }
        }
    }

    private function validateUpload(UploadedFileInterface $upload, string $error): void
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new HttpProblem(422, 'Invalid extraction', $error);
        }
        $size = $upload->getSize();
        if ($size === null || $size < 16 || $size > $this->maxImageBytes) {
            throw new HttpProblem(413, 'Image rejected', 'Image size is outside the configured limit.');
        }
    }

    private function readUpload(UploadedFileInterface $upload): string
    {
        $stream = $upload->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $stream->getContents();
    }

    /**
     * @param array<mixed> $uploads */
    private function closeUploads(array $uploads): void
    {
        foreach ($uploads as $upload) {
            if (is_array($upload)) {
                $this->closeUploads($upload);
                continue;
            }
            if (! $upload instanceof UploadedFileInterface) {
                continue;
            }
            try {
                $upload->getStream()->close();
            } catch (Throwable) {
                // Request-scoped cleanup is best effort and never replaces the
                // authoritative extraction response or validation failure.
            }
        }
    }

    /**
     * @param array<string, mixed> $body */
    private function extractStored(
        AuthenticatedIdentity $identity,
        string $homeId,
        array $body,
    ): ResponseInterface {
        $this->requireExactKeys(
            $body,
            ['assetIds', 'kind', 'targetId', 'transmissionConsent'],
            ['assetIds', 'kind', 'transmissionConsent'],
        );
        if ($body['transmissionConsent'] !== true) {
            throw new HttpProblem(
                422,
                'Transmission consent required',
                'Confirm the selected provider and privacy mode before sending stored media.',
            );
        }

        return new JsonResponse($this->ai->extractStoredMedia(
            $identity,
            $homeId,
            (string) $body['kind'],
            isset($body['targetId']) ? (string) $body['targetId'] : null,
            true,
            $this->stringList($body['assetIds']),
        ), 201);
    }

    /**
     *
     * @param array<string, mixed> $body
     *
     * @param list<string> $allowed
     *
     * @param list<string> $required
     */
    private function requireExactKeys(array $body, array $allowed, array $required): void
    {
        if (array_diff(array_keys($body), $allowed) !== [] || array_diff($required, array_keys($body)) !== []) {
            throw new HttpProblem(422, 'Invalid extraction', 'Multipart fields do not match the contract.');
        }
    }

    private function isExplicitTrue(mixed $value): bool
    {
        return $value === true || $value === 'true';
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
