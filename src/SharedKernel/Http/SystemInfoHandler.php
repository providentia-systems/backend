<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Providentia\SharedKernel\Application\SystemInformationProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SystemInfoHandler implements RequestHandlerInterface
{
    public function __construct(private readonly SystemInformationProvider $information)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse($this->information->information());
    }
}
