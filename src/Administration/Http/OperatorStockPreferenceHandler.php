<?php

declare(strict_types=1);

namespace Providentia\Administration\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Administration\Application\OperatorStockPreferenceService;
use Providentia\SharedKernel\Http\RequestIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OperatorStockPreferenceHandler implements RequestHandlerInterface
{
    public function __construct(private readonly OperatorStockPreferenceService $preferences)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestIdentity::require($request);
        $homeId = (string) $request->getAttribute('homeId', '');
        $productId = (string) $request->getAttribute('homeProductId', '');
        /** @var array<string, mixed> $input */
        $input = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $result = $request->getMethod() === 'PUT'
            ? $this->preferences->put($actor, $homeId, $productId, $input)
            : $this->preferences->get($actor, $homeId, $productId);
        return new JsonResponse($result, 200, ['Cache-Control' => 'no-store']);
    }
}
