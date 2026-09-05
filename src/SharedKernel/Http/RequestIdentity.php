<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\Identity\Http\BearerAuthenticationMiddleware;
use Psr\Http\Message\ServerRequestInterface;

final class RequestIdentity
{
    public static function require(ServerRequestInterface $request): AuthenticatedIdentity
    {
        $identity = $request->getAttribute(BearerAuthenticationMiddleware::ATTRIBUTE);
        if (! $identity instanceof AuthenticatedIdentity) {
            throw new HttpProblem(401, 'Authentication required', 'Sign in to continue.');
        }
        return $identity;
    }
}
