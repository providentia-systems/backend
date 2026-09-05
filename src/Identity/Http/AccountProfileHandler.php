<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AccountProfileService;
use Providentia\SharedKernel\Http\RequestIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AccountProfileHandler implements RequestHandlerInterface
{
    public function __construct(private readonly AccountProfileService $profiles, private readonly string $action)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = RequestIdentity::require($request);
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $result = match ($this->action) {
            'get' => $this->profiles->get($identity),
            'update', 'onboard' => $this->profiles->save($identity, $body, $this->action === 'onboard'),
            'email-request' => $this->profiles->requestEmail($identity, (string) ($body['email'] ?? ''), $ip),
            'email-verify' => $this->profiles->verifyEmail($identity, $body, $ip),
            'security-request' => $this->profiles->requestSecurityCode($identity, (string) ($body['action'] ?? ''), $ip),
            'security-verify' => $this->profiles->verifySecurityCode($identity, $body, $ip),
            'email-primary', 'email-remove' => $this->changeEmail($request, $body),
            default => throw new \LogicException('Unknown profile action.'),
        };
        return new JsonResponse($result, 200, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, mixed> $body
     * @return array{changed: true}
     */
    private function changeEmail(ServerRequestInterface $request, array $body): array
    {
        $this->profiles->changeEmail(RequestIdentity::require($request), (string) $request->getAttribute('emailId', ''), (string) ($body['proofToken'] ?? ''), $this->action === 'email-primary');
        return ['changed' => true];
    }
}
