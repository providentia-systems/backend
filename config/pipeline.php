<?php

declare(strict_types=1);

use Mezzio\Application;
use Mezzio\Helper\BodyParams\BodyParamsMiddleware;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Providentia\SharedKernel\Http\ProblemDetailsMiddleware;
use Providentia\SharedKernel\Http\RequestIdMiddleware;
use Providentia\SharedKernel\Http\CorsMiddleware;
use Providentia\SharedKernel\Http\NotFoundHandler;
use Providentia\SharedKernel\Http\SecurityHeadersMiddleware;

return static function (Application $app): void {
    $app->pipe(RequestIdMiddleware::class);
    $app->pipe(ProblemDetailsMiddleware::class);
    $app->pipe(SecurityHeadersMiddleware::class);
    $app->pipe(CorsMiddleware::class);
    $app->pipe(Mezzio\Helper\ServerUrlMiddleware::class);
    $app->pipe(Mezzio\Helper\UrlHelperMiddleware::class);
    $app->pipe(BodyParamsMiddleware::class);
    $app->pipe(RouteMiddleware::class);
    $app->pipe(DispatchMiddleware::class);
    $app->pipe(NotFoundHandler::class);
};
