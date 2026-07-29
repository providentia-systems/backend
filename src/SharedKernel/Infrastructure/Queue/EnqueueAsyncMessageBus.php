<?php

declare(strict_types=1);

namespace Providentia\SharedKernel\Infrastructure\Queue;

use Interop\Queue\Context;
use Providentia\SharedKernel\Application\Async\AsyncMessage;
use Providentia\SharedKernel\Application\Async\AsyncMessageBus;

final class EnqueueAsyncMessageBus implements AsyncMessageBus
{
    public function __construct(private readonly Context $context)
    {
    }

    public function publish(AsyncMessage $message): void
    {
        $body = json_encode([
            'id' => $message->id,
            'type' => $message->type,
            'occurredAt' => $message->occurredAt->format(DATE_ATOM),
            'payload' => $message->payload,
        ], JSON_THROW_ON_ERROR);
        $transportMessage = $this->context->createMessage($body, [
            'content_type' => 'application/json',
            'message_type' => $message->type,
        ]);
        $transportMessage->setMessageId($message->id);
        $this->context->createProducer()->send(
            $this->context->createQueue($message->queue),
            $transportMessage,
        );
    }
}

