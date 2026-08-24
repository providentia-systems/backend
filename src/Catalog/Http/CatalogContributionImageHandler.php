<?php

declare(strict_types=1);

namespace Providentia\Catalog\Http;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Catalog\Application\CatalogContributionImageService;
use Providentia\Catalog\Application\CatalogImageContent;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CatalogContributionImageHandler implements RequestHandlerInterface
{
    public function __construct(
        private CatalogContributionImageService $images,
        private string $action,
        private int $maxUploadBytes,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->action === 'content') {
            return $this->imageResponse(
                $this->images->publicAsset((string) $request->getAttribute('assetDigest', '')),
                true,
            );
        }
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        if ($this->action === 'publication') {
            $fields = array_keys($body);
            sort($fields);
            if ($fields !== ['expectedContributionRevision', 'expectedIconRevision', 'productId']) {
                throw new HttpProblem(422, 'Validation failed', 'The request fields do not match the operation.');
            }
        }

        return match ($this->action) {
            'upload' => $this->upload($identity, $request, $body),
            'preview' => $this->imageResponse($this->images->preview(
                $identity,
                (string) $request->getAttribute('contributionId', ''),
                (int) ($request->getQueryParams()['expectedRevision'] ?? 0),
            ), false),
            'publication' => new JsonResponse($this->images->publish(
                $identity,
                (string) $request->getAttribute('contributionId', ''),
                (string) ($body['productId'] ?? ''),
                (int) ($body['expectedContributionRevision'] ?? 0),
                (int) ($body['expectedIconRevision'] ?? -1),
            )),
            default => throw new \LogicException('Unknown catalog contribution image action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function upload(
        AuthenticatedIdentity $identity,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $expectedFields = [
            'altText',
            'expectedConsentRevision',
            'rightsDeclarationVersion',
            'sourceDigest',
            'sourceEntityId',
            'submissionConfirmed',
            'submissionId',
        ];
        $actualFields = array_keys($body);
        sort($actualFields);
        $uploadedFiles = $request->getUploadedFiles();
        if ($actualFields !== $expectedFields || array_keys($uploadedFiles) !== ['image']) {
            throw new HttpProblem(422, 'Invalid image contribution', 'Only the documented image fields are accepted.');
        }
        $uploaded = $uploadedFiles['image'];
        if (! $uploaded instanceof UploadedFileInterface || $uploaded->getError() !== UPLOAD_ERR_OK) {
            throw new HttpProblem(422, 'Invalid image contribution', 'One successfully uploaded image is required.');
        }
        $size = $uploaded->getSize();
        if ($size === null || $size < 16 || $size > $this->maxUploadBytes) {
            throw new HttpProblem(413, 'Image rejected', 'Image size is outside the five MiB limit.');
        }
        if ($body['submissionConfirmed'] !== true && $body['submissionConfirmed'] !== 'true') {
            throw new HttpProblem(422, 'Confirmation required', 'Confirm submission of this exact image.');
        }
        $stream = $uploaded->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $submission = $this->images->upload(
            $identity,
            (string) $request->getAttribute('homeId', ''),
            (string) ($body['submissionId'] ?? ''),
            (string) ($body['sourceEntityId'] ?? ''),
            (int) ($body['expectedConsentRevision'] ?? 0),
            (string) ($body['altText'] ?? ''),
            (string) ($body['rightsDeclarationVersion'] ?? ''),
            (string) $body['sourceDigest'],
            $stream->getContents(),
        );

        return new JsonResponse($submission->contribution, $submission->created ? 201 : 200);
    }

    private function imageResponse(CatalogImageContent $content, bool $public): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write($content->bytes);
        $response = $response
            ->withHeader('Content-Type', $content->mediaType)
            ->withHeader('Content-Length', (string) strlen($content->bytes))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Content-SHA256', $content->digest)
            ->withHeader('ETag', '"sha256-' . $content->digest . '"');
        if ($public) {
            return $response->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
        }

        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
