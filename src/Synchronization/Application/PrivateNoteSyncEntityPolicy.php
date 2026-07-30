<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use Providentia\SharedKernel\Application\Problem;

final class PrivateNoteSyncEntityPolicy implements SyncEntityPolicy
{
    public function entityType(): string
    {
        return 'private-note';
    }

    public function validatePut(array $payload): void
    {
        $this->rejectUnknownFields($payload);
        $body = $payload['body'] ?? null;
        if (! is_string($body) || mb_strlen($body) < 1 || mb_strlen($body) > 4000) {
            throw new Problem(
                422,
                'Invalid operation',
                'private-note.body must contain 1 to 4000 characters.',
            );
        }

        $title = $payload['title'] ?? null;
        if (
            array_key_exists('title', $payload)
            && (! is_string($title) || mb_strlen($title) > 120)
        ) {
            throw new Problem(
                422,
                'Invalid operation',
                'private-note.title must contain at most 120 characters.',
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function rejectUnknownFields(array $payload): void
    {
        if (array_diff(array_keys($payload), ['title', 'body']) !== []) {
            throw new Problem(
                422,
                'Invalid operation',
                'The payload contains unknown or server-owned fields.',
            );
        }
    }
}
