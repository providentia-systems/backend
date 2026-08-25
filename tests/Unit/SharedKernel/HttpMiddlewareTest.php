<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Http\CorsMiddleware;
use Providentia\SharedKernel\Http\HttpProblem;
use Providentia\SharedKernel\Http\RequestIdMiddleware;
use Providentia\SharedKernel\Http\SecurityHeadersMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HttpMiddlewareTest extends TestCase
{
    public function testRequestIdIsPropagatedToHandlerAndResponse(): void
    {
        $seen = null;
        $handler = new CallbackRequestHandler(
            static function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request->getHeaderLine('X-Request-Id');

                return new JsonResponse(['ok' => true]);
            },
        );
        $response = (new RequestIdMiddleware())->process(
            $this->request('GET', ['X-Request-Id' => ['client.request-1']]),
            $handler,
        );

        self::assertSame('client.request-1', $seen);
        self::assertSame('client.request-1', $response->getHeaderLine('X-Request-Id'));
    }

    public function testInvalidRequestIdIsReplacedBySecureShape(): void
    {
        $response = (new RequestIdMiddleware())->process(
            $this->request('GET', ['X-Request-Id' => ['invalid request id']]),
            new CallbackRequestHandler(
                static fn (ServerRequestInterface $request): ResponseInterface => new JsonResponse([
                    'method' => $request->getMethod(),
                ]),
            ),
        );

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{32}$/',
            $response->getHeaderLine('X-Request-Id'),
        );
    }

    public function testSecurityHeadersAreAppliedToSuccessfulResponse(): void
    {
        $response = (new SecurityHeadersMiddleware())->process(
            $this->request(),
            new CallbackRequestHandler(
                static fn (ServerRequestInterface $request): ResponseInterface => new JsonResponse([
                    'method' => $request->getMethod(),
                ]),
            ),
        );

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString(
            "frame-ancestors 'none'",
            $response->getHeaderLine('Content-Security-Policy'),
        );
    }

    public function testSecurityHeadersPreserveHandlerPolicies(): void
    {
        $policy = "default-src 'none'; script-src 'nonce-test-nonce'; form-action 'self'";
        $response = (new SecurityHeadersMiddleware())->process(
            $this->request(),
            new CallbackRequestHandler(
                static fn (ServerRequestInterface $request): ResponseInterface =>
                    (new HtmlResponse('launch'))
                        ->withHeader('Content-Security-Policy', $policy)
                        ->withHeader('Referrer-Policy', 'same-origin'),
            ),
        );

        self::assertSame($policy, $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testSecurityHeadersPreservePrivatePreviewCachePolicy(): void
    {
        $response = (new SecurityHeadersMiddleware())->process(
            $this->request(),
            new CallbackRequestHandler(
                static fn (ServerRequestInterface $request): ResponseInterface =>
                    (new JsonResponse(['method' => $request->getMethod()]))
                    ->withHeader('Cache-Control', 'private, no-store'),
            ),
        );

        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testSecurityHeadersPreserveImmutablePublicAssetCachePolicy(): void
    {
        $response = (new SecurityHeadersMiddleware())->process(
            $this->request(),
            new CallbackRequestHandler(
                static fn (ServerRequestInterface $request): ResponseInterface =>
                    (new JsonResponse(['method' => $request->getMethod()]))
                    ->withHeader('Cache-Control', 'public, max-age=31536000, immutable'),
            ),
        );

        self::assertSame(
            'public, max-age=31536000, immutable',
            $response->getHeaderLine('Cache-Control'),
        );
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testCorsPreflightForAllowedOriginDoesNotInvokeApplication(): void
    {
        $handler = new CallbackRequestHandler(
            static function (ServerRequestInterface $request): ResponseInterface {
                unset($request);
                self::fail('A preflight request reached the application handler.');
            },
        );
        $response = (new CorsMiddleware(['https://app.example.test']))->process(
            $this->request('OPTIONS', ['Origin' => ['https://app.example.test']]),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(
            'https://app.example.test',
            $response->getHeaderLine('Access-Control-Allow-Origin'),
        );
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testJsonApiPostPassesForConfiguredFlutterWebOrigin(): void
    {
        $origin = 'http://127.0.0.1:8080';
        $request = new ServerRequest(
            [],
            [],
            new Uri($origin . '/api/v1/auth/login-links'),
            'POST',
            'php://memory',
            ['Origin' => [$origin]],
        );
        $response = (new CorsMiddleware([$origin]))->process(
            $request,
            new CallbackRequestHandler(
                static fn (ServerRequestInterface $request): ResponseInterface => new JsonResponse(
                    ['accepted' => true],
                ),
            ),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($origin, $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsRejectsUnknownOrigin(): void
    {
        $middleware = new CorsMiddleware(['https://app.example.test']);

        try {
            $middleware->process(
                $this->request('GET', ['Origin' => ['https://attacker.example']]),
                new CallbackRequestHandler(
                    static fn (ServerRequestInterface $request): ResponseInterface => new JsonResponse([
                        'method' => $request->getMethod(),
                    ]),
                ),
            );
            self::fail('An unknown origin was accepted.');
        } catch (HttpProblem $problem) {
            self::assertSame(403, $problem->status);
        }
    }

    public function testCorsRejectsNullOrigin(): void
    {
        $middleware = new CorsMiddleware(['https://app.example.test']);

        try {
            $middleware->process(
                $this->request('POST', ['Origin' => ['null']]),
                new CallbackRequestHandler(
                    static fn (ServerRequestInterface $request): ResponseInterface => new JsonResponse([
                        'method' => $request->getMethod(),
                    ]),
                ),
            );
            self::fail('An opaque null origin was accepted.');
        } catch (HttpProblem $problem) {
            self::assertSame(403, $problem->status);
        }
    }

    /**
     * @param array<non-empty-string, list<string>> $headers
     */
    private function request(string $method = 'GET', array $headers = []): ServerRequestInterface
    {
        return new ServerRequest(
            [],
            [],
            new Uri('https://example.test/api'),
            $method,
            'php://memory',
            $headers,
        );
    }
}
