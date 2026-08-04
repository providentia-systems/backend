<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;
use Providentia\AiIntegration\Application\Media\PrivateMediaService;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PrivateMediaHandler implements RequestHandlerInterface
{
    public function __construct(
        private PrivateMediaService $media,
        private string $action,
        private int $maxMediaBytes,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }
        $homeId = (string) $request->getAttribute('homeId', '');
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $query = $request->getQueryParams();

        return match ($this->action) {
            'media.upload' => $this->upload($identity, $homeId, $request, $body),
            'media.list' => new JsonResponse($this->media->list(
                $identity,
                $homeId,
                (int) ($query['limit'] ?? 50),
                isset($query['beforeId']) ? (string) $query['beforeId'] : null,
            )),
            'media.download' => $this->download($identity, $homeId, $request),
            'media.delete' => $this->delete($identity, $homeId, $request),
            'media.retention' => $this->retention($identity, $homeId, $request, $body),
            'media.export' => new JsonResponse(['items' => $this->media->export(
                $identity,
                $homeId,
                (int) ($query['limit'] ?? 100),
            )]),
            default => throw new \LogicException('Unknown private-media action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function upload(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $uploaded = $request->getUploadedFiles()['media'] ?? null;
        if (! $uploaded instanceof UploadedFileInterface || $uploaded->getError() !== UPLOAD_ERR_OK) {
            throw new HttpProblem(422, 'Invalid media', 'One successfully uploaded media object is required.');
        }
        $size = $uploaded->getSize();
        if ($size === null || $size < 16 || $size > $this->maxMediaBytes) {
            throw new HttpProblem(413, 'Media rejected', 'Media size is outside the configured limit.');
        }
        $stream = $uploaded->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return new JsonResponse($this->media->upload(
            $identity,
            $homeId,
            (string) ($body['retention'] ?? 'transient'),
            (string) ($uploaded->getClientMediaType() ?? ''),
            $uploaded->getClientFilename(),
            $stream->getContents(),
        ), 201);
    }

    private function download(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $download = $this->media->download(
            $identity,
            $homeId,
            (string) $request->getAttribute('assetId', ''),
        );
        $response = new Response();
        $response->getBody()->write($download['bytes']);
        $mimeType = (string) ($download['metadata']['mimeType'] ?? 'application/octet-stream');
        $assetId = (string) $request->getAttribute('assetId', 'media');
        $filename = preg_match('/^[A-Za-z0-9-]{1,64}$/', $assetId) === 1 ? $assetId : 'media';

        return $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string) strlen($download['bytes']))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function delete(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $this->media->delete($identity, $homeId, (string) $request->getAttribute('assetId', ''));

        return new EmptyResponse(204);
    }

    /** @param array<string, mixed> $body */
    private function retention(
        AuthenticatedIdentity $identity,
        string $homeId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->media->updateRetention(
            $identity,
            $homeId,
            (string) $request->getAttribute('assetId', ''),
            (string) ($body['retention'] ?? ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }
}
