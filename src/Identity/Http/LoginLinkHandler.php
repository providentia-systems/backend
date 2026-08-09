<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\EmptyResponse;
use Providentia\Identity\Application\LoginLinkService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LoginLinkHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly LoginLinkService $loginLinks,
        private readonly string $action,
        private readonly bool $cookieSecure = true,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $requestId = (string) $request->getAttribute('requestId', '');

        return match ($this->action) {
            'start' => new JsonResponse($this->loginLinks->start($body), 202),
            'status' => new JsonResponse($this->loginLinks->status(
                $requestId,
                (string) ($body['pollToken'] ?? ''),
            )),
            'cancel' => $this->cancel($requestId, (string) ($body['pollToken'] ?? '')),
            'exchange' => $this->exchange($requestId, $body),
            default => throw new \LogicException('Unknown login-link action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function exchange(string $requestId, array $body): ResponseInterface
    {
        $session = $this->loginLinks->exchange(
            $requestId,
            (string) ($body['pollToken'] ?? ''),
            (string) ($body['codeVerifier'] ?? ''),
            (string) ($body['state'] ?? ''),
        );

        return ($session['transport'] ?? 'native') === 'web'
            ? SessionResponseFactory::web($session, $this->cookieSecure)
            : new JsonResponse($session);
    }

    private function cancel(string $requestId, string $pollToken): ResponseInterface
    {
        $this->loginLinks->cancel($requestId, $pollToken);

        return new EmptyResponse(204);
    }
}
