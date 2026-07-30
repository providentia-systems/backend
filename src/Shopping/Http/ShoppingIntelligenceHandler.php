<?php

declare(strict_types=1);

namespace Providentia\Shopping\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Providentia\Shopping\Application\ShoppingIntelligenceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ShoppingIntelligenceHandler implements RequestHandlerInterface
{
    public function __construct(
        private ShoppingIntelligenceService $intelligence,
        private string $action,
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

        return match ($this->action) {
            'estimates.list' => new JsonResponse([
                'data' => $this->intelligence->estimates($identity, $homeId),
            ]),
            'suggestions.list' => new JsonResponse([
                'data' => $this->intelligence->suggestions($identity, $homeId),
            ]),
            'runs.create' => new JsonResponse($this->intelligence->generate(
                $identity,
                $homeId,
                (int) ($body['horizonDays'] ?? 14),
            ), 201),
            'explanation.get' => new JsonResponse($this->intelligence->explanation(
                $identity,
                $homeId,
                (string) $request->getAttribute('suggestionId', ''),
            )),
            'prices.list' => new JsonResponse([
                'data' => $this->intelligence->priceComparisons($identity, $homeId),
                'currencyPolicy' => 'never-compare-across-currencies',
            ]),
            'preferences.get' => new JsonResponse($this->intelligence->preference(
                $identity,
                $homeId,
                (string) $request->getAttribute('homeProductId', ''),
            )),
            'preferences.put' => new JsonResponse($this->intelligence->putPreference(
                $identity,
                $homeId,
                (string) $request->getAttribute('homeProductId', ''),
                $body,
            )),
            'feedback.create' => new JsonResponse($this->intelligence->feedback(
                $identity,
                $homeId,
                (string) $request->getAttribute('suggestionId', ''),
                (string) ($body['decision'] ?? ''),
                isset($body['resultQuantity']) ? (string) $body['resultQuantity'] : null,
                (string) ($body['reason'] ?? ''),
            ), 201),
            'backtests.create' => new JsonResponse($this->intelligence->backtest(
                $identity,
                $homeId,
                $this->stringList($body['cutoffs'] ?? null),
                (int) ($body['evaluationDays'] ?? 30),
            ), 201),
            'backtests.get' => new JsonResponse($this->intelligence->backtestResult(
                $identity,
                $homeId,
                (string) $request->getAttribute('backtestId', ''),
            )),
            default => throw new \LogicException('Unknown shopping intelligence action.'),
        };
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
