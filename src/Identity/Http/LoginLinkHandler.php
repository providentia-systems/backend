<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\LoginApplicationKind;
use Providentia\Identity\Application\LoginLinkService;
use Providentia\SharedKernel\Application\Problem;
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
            'proof' => $this->proof($requestId, $body),
            'review' => $this->review($requestId, $body),
            'decision' => $this->decision($requestId, $body),
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
    private function proof(string $requestId, array $body): ResponseInterface
    {
        $this->requireExactKeys($body, ['applicationKind', 'approvalToken']);

        return new JsonResponse($this->loginLinks->proof(
            $requestId,
            $this->approvalToken($body),
            (string) $body['applicationKind'],
        ));
    }

    /** @param array<string, mixed> $body */
    private function review(string $requestId, array $body): ResponseInterface
    {
        $this->requireExactKeys($body, ['applicationKind', 'approvalToken']);

        return new JsonResponse($this->loginLinks->review(
            $requestId,
            $this->approvalToken($body),
            (string) $body['applicationKind'],
        ));
    }

    /** @param array<string, mixed> $body */
    private function decision(string $requestId, array $body): ResponseInterface
    {
        $this->requireExactKeys($body, ['applicationKind', 'approvalToken', 'decision']);
        $application = LoginApplicationKind::fromInput((string) $body['applicationKind']);
        $approvalToken = $this->approvalToken($body);
        $decision = (string) $body['decision'];
        if (! in_array($decision, ['approve', 'deny'], true)) {
            throw new Problem(422, 'Validation failed', 'decision must be approve or deny.');
        }

        try {
            if ($decision === 'approve') {
                $this->loginLinks->approve($requestId, $approvalToken, $application->value);
            } else {
                $this->loginLinks->deny($requestId, $approvalToken, $application->value);
            }
        } catch (Problem $problem) {
            // A decision is deliberately replay-safe and non-disclosing. The
            // originating client observes the terminal result through its
            // separate private polling proof.
            if (! in_array($problem->status, [403, 404, 409, 410], true)) {
                throw $problem;
            }
        }

        return new JsonResponse([
            'requestId' => $this->requestId($requestId),
            'applicationKind' => $application->value,
            'status' => 'received',
        ], 202);
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

    /**
     * @param array<string, mixed> $body
     * @param list<string> $expected
     */
    private function requireExactKeys(array $body, array $expected): void
    {
        $actual = array_keys($body);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new Problem(422, 'Validation failed', 'The request body does not match the operation.');
        }
    }

    /** @param array<string, mixed> $body */
    private function approvalToken(array $body): string
    {
        $token = (string) $body['approvalToken'];
        if (preg_match('/^[A-Za-z0-9_-]{40,128}$/', $token) !== 1) {
            throw new Problem(422, 'Validation failed', 'approvalToken has an invalid format.');
        }

        return $token;
    }

    private function requestId(string $requestId): string
    {
        $requestId = mb_strtolower(trim($requestId));
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $requestId,
            ) !== 1
        ) {
            throw new Problem(422, 'Validation failed', 'requestId must be a UUID.');
        }

        return $requestId;
    }
}
