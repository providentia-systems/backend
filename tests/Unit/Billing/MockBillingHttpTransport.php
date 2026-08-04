<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Billing;

use Providentia\Billing\Application\BillingHttpRequest;
use Providentia\Billing\Application\BillingHttpResponse;
use Providentia\Billing\Application\BillingHttpTransport;

final class MockBillingHttpTransport implements BillingHttpTransport
{
    /** @var list<BillingHttpRequest> */
    public array $requests = [];

    /** @var list<BillingHttpResponse> */
    private array $responses = [];

    /** @param array<string, list<string>> $headers */
    public function enqueueFixture(int $status, string $fixture, array $headers = []): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/billing/' . $fixture;
        $body = file_get_contents($path);
        if (! is_string($body)) {
            throw new \RuntimeException('Billing fixture could not be read: ' . $fixture);
        }
        $this->responses[] = new BillingHttpResponse($status, $headers, $body);
    }

    public function send(BillingHttpRequest $request): BillingHttpResponse
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if (! $response instanceof BillingHttpResponse) {
            throw new \RuntimeException('No mock billing HTTP response remains.');
        }

        return $response;
    }

    public function assertExhausted(): void
    {
        if ($this->responses !== []) {
            throw new \RuntimeException('Not every mock billing HTTP response was consumed.');
        }
    }
}
