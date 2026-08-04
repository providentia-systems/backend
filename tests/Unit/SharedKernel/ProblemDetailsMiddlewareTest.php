<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Http\ProblemDetailsMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class ProblemDetailsMiddlewareTest extends TestCase
{
    public function testApplicationProblemIsRenderedAsRfcProblemDetails(): void
    {
        $logger = new RecordingProblemLogger();
        $middleware = new ProblemDetailsMiddleware(false, $logger);
        $response = $middleware->process(
            $this->request(),
            new CallbackRequestHandler(static function (ServerRequestInterface $request): ResponseInterface {
                unset($request);
                throw new Problem(
                    422,
                    'Validation failed',
                    'The request value is invalid.',
                    'https://providentia.invalid/problems/validation',
                );
            }),
        );
        $body = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('Validation failed', $body['title']);
        self::assertSame('The request value is invalid.', $body['detail']);
        self::assertSame('request-123', $body['requestId']);
        self::assertSame('/api', $body['instance']);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(422, $logger->records[0]['context']['status']);
    }

    public function testUnexpectedFailuresAreHiddenOutsideDebugMode(): void
    {
        $logger = new RecordingProblemLogger();
        $response = (new ProblemDetailsMiddleware(false, $logger))->process(
            $this->request('https://example.test/api?token=secret-token'),
            new CallbackRequestHandler(static function (ServerRequestInterface $request): ResponseInterface {
                unset($request);
                throw new RuntimeException('database credentials leaked');
            }),
        );
        $body = $this->body($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('The request could not be completed.', $body['detail']);
        self::assertStringNotContainsString('credentials', (string) $response->getBody());
        self::assertSame('/api', $body['instance']);
        self::assertSame('error', $logger->records[0]['level']);
        $encodedLog = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('database credentials leaked', $encodedLog);
        self::assertStringNotContainsString('secret-token', $encodedLog);
    }

    public function testDebugModeExposesUnexpectedFailureMessage(): void
    {
        $response = (new ProblemDetailsMiddleware(true, new RecordingProblemLogger()))->process(
            $this->request(),
            new CallbackRequestHandler(static function (ServerRequestInterface $request): ResponseInterface {
                unset($request);
                throw new RuntimeException('diagnostic detail');
            }),
        );

        self::assertSame('diagnostic detail', $this->body($response)['detail']);
    }

    public function testInvalidRequestIdentifierIsNotWrittenToTheLogOrResponse(): void
    {
        $logger = new RecordingProblemLogger();
        $response = (new ProblemDetailsMiddleware(false, $logger))->process(
            $this->request(
                'https://example.test/api',
                ['X-Request-Id' => ['attacker forged-log']],
            ),
            new CallbackRequestHandler(static function (ServerRequestInterface $request): ResponseInterface {
                unset($request);
                throw new RuntimeException('failure');
            }),
        );
        $requestId = $response->getHeaderLine('X-Request-Id');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $requestId);
        self::assertSame($requestId, $logger->records[0]['context']['request_id']);
    }

    /** @param array<non-empty-string, list<string>> $headers */
    private function request(
        string $uri = 'https://example.test/api',
        array $headers = ['X-Request-Id' => ['request-123']],
    ): ServerRequestInterface {
        return new ServerRequest(
            [],
            [],
            new Uri($uri),
            'POST',
            'php://memory',
            $headers,
        );
    }

    /** @return array<string, mixed> */
    private function body(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($body)) {
            self::fail('Expected a JSON object response.');
        }

        return $body;
    }
}

final class RecordingProblemLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
