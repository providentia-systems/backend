<?php

declare(strict_types=1);

namespace Providentia\Geography\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\Geography\Application\CountryService;
use Providentia\SharedKernel\Http\RequestIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CountryHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CountryService $countries,
        private readonly string $action,
    ) {
    }

    public function handle(
        ServerRequestInterface $request,
    ): ResponseInterface {
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody())
            ? $request->getParsedBody()
            : [];
        $query = $request->getQueryParams();
        $country = strtoupper(
            (string) $request->getAttribute('countryCode', ''),
        );
        $result = match ($this->action) {
            'list' => ['data' => $this->countries->countries()],
            'policy' => $this->countries->registrationPolicy($country),
            'states', 'cities' => [
                'data' => $this->countries->places(
                    $country,
                    isset($query['stateId'])
                        ? (int) $query['stateId']
                        : null,
                    (string) ($query['search'] ?? ''),
                    $this->action === 'cities',
                    (int) ($query['offset'] ?? 0),
                ),
            ],
            'admin-list' => [
                'data' => $this->countries->countries(RequestIdentity::require($request)),
            ],
            'settings' => $this->countries->settings(RequestIdentity::require($request), $country),
            'configure' => $this->configure($request, $body, $country),
            'policies' => [
                'data' => $this->countries->policies(
                    RequestIdentity::require($request),
                    isset($query['countryCode'])
                        ? (string) $query['countryCode']
                        : null,
                ),
            ],
            'policy-create', 'policy-update' => $this->countries->savePolicy(
                RequestIdentity::require($request),
                $this->action === 'policy-create'
                    ? null
                    : (string) $request->getAttribute('policyId', ''),
                $body,
            ),
            'jobs' => [
                'data' => $this->countries->jobs(RequestIdentity::require($request)),
            ],
            'update' => $this->countries->requestUpdate(RequestIdentity::require($request)),
            default => throw new \LogicException('Unknown country action.'),
        };
        return new JsonResponse($result);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{changed: true}
     */
    private function configure(
        ServerRequestInterface $request,
        array $body,
        string $country,
    ): array {
        $this->countries->configure(
            RequestIdentity::require($request),
            $country,
            $body,
        );
        return ['changed' => true];
    }
}
