<?php

declare(strict_types=1);

namespace Providentia\Billing\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Billing\Application\BillingService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class BillingWebhookHandler implements RequestHandlerInterface
{
    public function __construct(private BillingService $billing)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $provider = (string) $request->getAttribute('provider', '');
        $body = (string) $request->getBody();

        return new JsonResponse([
            'status' => $this->billing->acceptWebhook($provider, $body, $request->getHeaders()),
        ], 202);
    }
}
