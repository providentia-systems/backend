<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Async\QueueMetricsProbe;
use Providentia\SharedKernel\Application\Health\SyncMetricsProbe;
use Providentia\SharedKernel\Http\MetricsHandler;
use Providentia\SharedKernel\Http\NotFoundHandler;
use Psr\Http\Message\ResponseInterface;

final class MetricsHandlerTest extends TestCase
{
    public function testDisabledMetricsReturnTheSharedJsonNotFoundResponse(): void
    {
        $response = $this->handler(false, hash('sha256', 'unused-credential'))
            ->handle($this->request('Bearer attacker-supplied-credential-1234567890'));

        $this->assertNotFound($response);
    }

    public function testUnauthorizedMetricsAreIndistinguishableFromDisabledMetrics(): void
    {
        $response = $this->handler(true, hash('sha256', 'expected-credential-at-least-32-bytes'))
            ->handle($this->request());

        $this->assertNotFound($response);
    }

    public function testDedicatedBearerCredentialAllowsPlainTextMetrics(): void
    {
        $credential = 'expected-credential-at-least-32-bytes';
        $response = $this->handler(true, hash('sha256', $credential))
            ->handle($this->request('Bearer ' . $credential));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'text/plain; version=0.0.4; charset=utf-8',
            $response->getHeaderLine('Content-Type'),
        );
        self::assertStringContainsString('providentia_metrics_up 1', (string) $response->getBody());
    }

    private function handler(bool $enabled, string $credentialHash): MetricsHandler
    {
        $queue = $this->createStub(QueueMetricsProbe::class);
        $queue->method('measure')->willReturn(['up' => 1, 'depth' => 0]);
        $sync = $this->createStub(SyncMetricsProbe::class);
        $sync->method('metrics')->willReturn([
            'operations' => 0,
            'accepted' => 0,
            'conflicts' => 0,
            'tombstones' => 0,
            'changes' => 0,
            'cursors' => 0,
        ]);

        return new MetricsHandler(
            new InMemoryOutboxStore([]),
            $queue,
            $sync,
            $enabled,
            $credentialHash,
            new NotFoundHandler(),
        );
    }

    private function request(string $authorization = ''): ServerRequest
    {
        $headers = ['X-Request-Id' => ['metrics-request-123']];
        if ($authorization !== '') {
            $headers['Authorization'] = [$authorization];
        }

        return new ServerRequest([], [], new Uri('https://api.example.test/metrics'), 'GET', 'php://memory', $headers);
    }

    private function assertNotFound(ResponseInterface $response): void
    {
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('metrics-request-123', $response->getHeaderLine('X-Request-Id'));
        self::assertJsonStringEqualsJsonString(json_encode([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'The requested API resource is unavailable.',
            'instance' => '/metrics',
            'requestId' => 'metrics-request-123',
        ], JSON_THROW_ON_ERROR), (string) $response->getBody());
    }
}
