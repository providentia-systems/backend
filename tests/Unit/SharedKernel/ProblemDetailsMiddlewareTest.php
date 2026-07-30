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
use RuntimeException;

final class ProblemDetailsMiddlewareTest extends TestCase
{
    public function testApplicationProblemIsRenderedAsRfcProblemDetails(): void
    {
        $middleware = new ProblemDetailsMiddleware(false);
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
        self::assertSame('https://example.test/api', $body['instance']);
    }

    public function testUnexpectedFailuresAreHiddenOutsideDebugMode(): void
    {
        $response = (new ProblemDetailsMiddleware(false))->process(
            $this->request(),
            new CallbackRequestHandler(static function (ServerRequestInterface $request): ResponseInterface {
                unset($request);
                throw new RuntimeException('database credentials leaked');
            }),
        );
        $body = $this->body($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('The request could not be completed.', $body['detail']);
        self::assertStringNotContainsString('credentials', (string) $response->getBody());
    }

    public function testDebugModeExposesUnexpectedFailureMessage(): void
    {
        $response = (new ProblemDetailsMiddleware(true))->process(
            $this->request(),
            new CallbackRequestHandler(static function (ServerRequestInterface $request): ResponseInterface {
                unset($request);
                throw new RuntimeException('diagnostic detail');
            }),
        );

        self::assertSame('diagnostic detail', $this->body($response)['detail']);
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequest(
            [],
            [],
            new Uri('https://example.test/api'),
            'POST',
            'php://memory',
            ['X-Request-Id' => ['request-123']],
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
