<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\ProfileMediaService;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Http\RequestIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ProfileMediaHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ProfileMediaService $media,
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
        $homeId = (string) $request->getAttribute('homeId', '');
        if ($this->action === 'home-profile') {
            return new JsonResponse(
                $request->getMethod() === 'GET'
                    ? $this->media->home($identity, $homeId)
                    : $this->media->saveHome($identity, $homeId, $body),
            );
        }
        if ($this->action === 'gravatar') {
            $this->media->selectGravatar(
                $identity,
                (string) ($body['emailId'] ?? ''),
                (int) ($body['expectedRevision'] ?? 0),
            );
            return new EmptyResponse(204);
        }
        $scope = $homeId === ''
            ? 'account'
            : 'home';
        $id = $homeId === ''
            ? (string) $request->getAttribute('userId', $identity->userId)
            : $homeId;
        if ($request->getMethod() === 'GET') {
            $image = $this->media->image(
                $identity,
                $scope,
                $id,
                $this->action === 'operator-image',
            );
            if ($image === null) {
                return new EmptyResponse(204, ['Cache-Control' => 'private, no-store']);
            }
            $response = new Response(
                'php://temp',
                200,
                [
                    'Content-Type' => 'image/webp',
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ],
            );
            $response->getBody()
                ->write($image['bytes']);
            return $response;
        }
        $bytes = null;
        if ($request->getMethod() !== 'DELETE') {
            $encoded = (string) ($body['imageBase64'] ?? '');
            if (strlen($encoded) > 2796204 || ($bytes = base64_decode($encoded, true)) === false) {
                throw new Problem(
                    422,
                    'Invalid image',
                    'Supply an image no larger than two MiB.',
                );
            }
        }
        $this->media->saveImage(
            $identity,
            $scope,
            $id,
            $bytes,
            (int) ($body['expectedRevision'] ?? -1),
        );
        return new EmptyResponse(204);
    }
}
