<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use DateTimeImmutable;
use DateTimeZone;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

final class SessionResponseFactory
{
    /** @param array<string, mixed> $tokens */
    public static function web(array $tokens, bool $cookieSecure = true): ResponseInterface
    {
        $accessExpiry = new DateTimeImmutable((string) $tokens['accessExpiresAt']);
        // A durable session has no idle expiry; browsers cap cookie lifetime
        // at 400 days, so the cookie is re-issued on every rotation while the
        // server-side session lives until it is explicitly revoked.
        $refreshExpiry = isset($tokens['refreshExpiresAt'])
            ? new DateTimeImmutable((string) $tokens['refreshExpiresAt'])
            : $accessExpiry->modify('+400 days');
        $accessMaxAge = max(0, $accessExpiry->getTimestamp() - time());
        $refreshMaxAge = ($tokens['refreshIdleTtlSeconds'] ?? null) === null
            ? 34560000
            : max(0, (int) $tokens['refreshIdleTtlSeconds']);
        $secure = '; Path=/' . ($cookieSecure ? '; Secure' : '') . '; SameSite=Strict';
        $response = new JsonResponse([
            'sessionId' => $tokens['sessionId'],
            'deviceId' => $tokens['deviceId'],
            'installationId' => $tokens['installationId'],
            'userId' => $tokens['userId'],
            'accessExpiresAt' => $tokens['accessExpiresAt'],
            'refreshExpiresAt' => $tokens['refreshExpiresAt'],
            'idleExpiresAt' => $tokens['idleExpiresAt'],
            'refreshIdleTtlSeconds' => $tokens['refreshIdleTtlSeconds'],
            'activeHomeId' => $tokens['activeHomeId'] ?? null,
            'csrfToken' => $tokens['csrfToken'],
            'transport' => 'web',
        ]);

        return $response
            ->withAddedHeader(
                'Set-Cookie',
                'providentia_access=' . rawurlencode((string) $tokens['accessToken']) . $secure
                . '; HttpOnly; Max-Age=' . $accessMaxAge . '; Expires=' . self::cookieDate($accessExpiry),
            )
            ->withAddedHeader(
                'Set-Cookie',
                'providentia_refresh=' . rawurlencode((string) $tokens['refreshToken']) . $secure
                . '; HttpOnly; Max-Age=' . $refreshMaxAge . '; Expires=' . self::cookieDate($refreshExpiry),
            )
            ->withAddedHeader(
                'Set-Cookie',
                'providentia_csrf=' . rawurlencode((string) $tokens['csrfToken']) . $secure
                . '; Max-Age=' . $refreshMaxAge . '; Expires=' . self::cookieDate($refreshExpiry),
            );
    }

    public static function cleared(int $status = 204, bool $cookieSecure = true): ResponseInterface
    {
        $secure = $cookieSecure ? '; Secure' : '';
        $expired = '=deleted; Path=/' . $secure
            . '; HttpOnly; SameSite=Strict; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';

        return (new EmptyResponse($status))
            ->withAddedHeader('Set-Cookie', 'providentia_access' . $expired)
            ->withAddedHeader('Set-Cookie', 'providentia_refresh' . $expired)
            ->withAddedHeader(
                'Set-Cookie',
                'providentia_csrf=deleted; Path=/' . $secure
                . '; SameSite=Strict; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            );
    }

    private static function cookieDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' GMT';
    }
}
