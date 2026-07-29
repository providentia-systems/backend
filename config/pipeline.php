<?php

declare(strict_types=1);

use Mezzio\Application;
use Mezzio\Helper\BodyParams\BodyParamsMiddleware;
use Providentia\SharedKernel\Http\ProblemDetailsMiddleware;
use Providentia\SharedKernel\Http\RequestIdMiddleware;

return static function (Application $app): void {
    $app->pipe(RequestIdMiddleware::class);
    $app->pipe(ProblemDetailsMiddleware::class);
    $app->pipe(Mezzio\Helper\ServerUrlMiddleware::class);
    $app->pipe(Mezzio\Helper\UrlHelperMiddleware::class);
    $app->pipe(BodyParamsMiddleware::class);
    $app->pipeRoutingMiddleware();
    $app->pipeDispatchMiddleware();
};
