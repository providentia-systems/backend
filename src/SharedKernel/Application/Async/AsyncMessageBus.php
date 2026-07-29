<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Application\Async;

interface AsyncMessageBus
{
    public function publish(AsyncMessage $message): void;
}

