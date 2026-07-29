<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\Async\AsyncMessageBus;
use RuntimeException;

final class RecordingBus implements AsyncMessageBus
{
    /** @var list<string> */
    public array $published = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function publish(AsyncMessage $message): void
    {
        if ($this->fail) {
            throw new RuntimeException('broker unavailable');
        }

        $this->published[] = $message->id;
    }
}
