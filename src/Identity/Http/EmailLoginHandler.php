<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\EmailLoginService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class EmailLoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EmailLoginService $login,
        private readonly bool $verify,
        private readonly bool $cookieSecure,
    ) {
    }

    public function handle(
        ServerRequestInterface $request,
    ): ResponseInterface {
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody())
            ? $request->getParsedBody()
            : [];
        $application = WebSessionContext::application($request);
        if ($application !== null && ! $this->verify && ($body['applicationKind'] ?? null) !== $application->value) {
            throw new \Providentia\SharedKernel\Http\HttpProblem(
                422,
                'Application context mismatch',
                'The login application must match the selected web session context.',
            );
        }
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        if (!$this->verify) {
            return new JsonResponse(
                $this->login->request($body, $ip),
                202,
                ['Cache-Control' => 'no-store'],
            );
        }
        $session = $this->login->verify($body, $ip, $application);
        return ($session['transport'] ?? '') === 'web'
            ? SessionResponseFactory::web($session, $this->cookieSecure, $application)
            : new JsonResponse($session, 200, ['Cache-Control' => 'no-store']);
    }
}
