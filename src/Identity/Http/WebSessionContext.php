<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Providentia\Identity\Application\LoginApplicationKind;
use Providentia\SharedKernel\Http\HttpProblem;
use Psr\Http\Message\ServerRequestInterface;

/** Selects a cookie namespace, never an account or an authorization grant. */
final class WebSessionContext
{
    public const HEADER = 'X-Providentia-Application';

    public static function application(ServerRequestInterface $request): ?LoginApplicationKind
    {
        $value = $request->getHeaderLine(self::HEADER);
        if ($value === '') {
            return null;
        }
        $application = LoginApplicationKind::tryFrom($value);
        if ($application === null) {
            throw new HttpProblem(422, 'Invalid application context', 'Choose homeowner or admin.');
        }

        return $application;
    }

    public static function cookie(string $credential, ?LoginApplicationKind $application): string
    {
        if (! in_array($credential, ['access', 'refresh', 'csrf'], true)) {
            throw new \InvalidArgumentException('Unknown session credential.');
        }

        return 'providentia_' . ($application === null ? '' : $application->value . '_') . $credential;
    }
}
