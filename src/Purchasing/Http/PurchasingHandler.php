<?php

declare(strict_types=1);

namespace Providentia\Purchasing\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Providentia\Purchasing\Application\PurchasingService;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PurchasingHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly PurchasingService $purchases,
        private readonly string $action,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $homeId = (string) $request->getAttribute('homeId', '');
        $receiptId = (string) $request->getAttribute('receiptId', '');
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $query = $request->getQueryParams();

        return match ($this->action) {
            'history' => new JsonResponse(['data' => $this->purchases->history(
                $identity,
                $homeId,
                isset($query['from']) ? (string) $query['from'] : null,
                isset($query['to']) ? (string) $query['to'] : null,
                isset($query['storeId']) ? (string) $query['storeId'] : null,
                (int) ($query['limit'] ?? 50),
                (int) ($query['offset'] ?? 0),
            )]),
            'get' => new JsonResponse($this->purchases->receipt($identity, $homeId, $receiptId)),
            'summary' => new JsonResponse($this->purchases->summary(
                $identity,
                $homeId,
                (int) ($query['recentDays'] ?? 90),
            )),
            'stores.create' => new JsonResponse($this->purchases->createStore(
                $identity,
                $homeId,
                (string) ($body['name'] ?? ''),
                (string) ($body['location'] ?? ''),
            ), 201),
            'create' => new JsonResponse($this->purchases->createReceipt(
                $identity,
                $homeId,
                isset($body['storeId']) ? (string) $body['storeId'] : null,
                (string) ($body['purchaseDate'] ?? ''),
                (string) ($body['currency'] ?? ''),
                isset($body['totalAmount']) ? (string) $body['totalAmount'] : null,
                (string) ($body['notes'] ?? ''),
                isset($body['sourceReference']) ? (string) $body['sourceReference'] : null,
            ), 201),
            'lines.create' => new JsonResponse($this->purchases->addLine(
                $identity,
                $homeId,
                $receiptId,
                (int) ($body['expectedReceiptRevision'] ?? 0),
                (string) ($body['rawDescription'] ?? ''),
                (string) ($body['quantity'] ?? ''),
                isset($body['originalPackText']) ? (string) $body['originalPackText'] : null,
                isset($body['unitPrice']) ? (string) $body['unitPrice'] : null,
                isset($body['lineTotal']) ? (string) $body['lineTotal'] : null,
            ), 201),
            'lines.approve' => $this->approveLine($identity, $homeId, $receiptId, $request, $body),
            'lines.unresolve' => $this->unresolveLine(
                $identity,
                $homeId,
                $receiptId,
                $request,
                $body,
            ),
            'commit' => new JsonResponse($this->purchases->commit(
                $identity,
                $homeId,
                $receiptId,
                (int) ($body['expectedRevision'] ?? 0),
            )),
            default => throw new \LogicException('Unknown purchasing action.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function unresolveLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        if (
            count($body) !== 1
            || ! array_key_exists('expectedRevision', $body)
            || ! is_int($body['expectedRevision'])
            || $body['expectedRevision'] < 1
        ) {
            throw new HttpProblem(
                422,
                'Validation failed',
                'The request must contain only a positive integer expectedRevision.',
            );
        }

        return new JsonResponse($this->purchases->unresolveLine(
            $identity,
            $homeId,
            $receiptId,
            (string) $request->getAttribute('lineId', ''),
            $body['expectedRevision'],
        ));
    }

    /** @param array<string, mixed> $body */
    private function approveLine(
        AuthenticatedIdentity $identity,
        string $homeId,
        string $receiptId,
        ServerRequestInterface $request,
        array $body,
    ): ResponseInterface {
        $this->purchases->approveLine(
            $identity,
            $homeId,
            $receiptId,
            (string) $request->getAttribute('lineId', ''),
            (string) ($body['homeProductId'] ?? ''),
            (int) ($body['expectedRevision'] ?? 0),
        );

        return new EmptyResponse(204);
    }

    private function identity(ServerRequestInterface $request): AuthenticatedIdentity
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'A valid access credential is required.');
        }

        return $identity;
    }
}
