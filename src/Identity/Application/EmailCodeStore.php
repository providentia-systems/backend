<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

interface EmailCodeStore
{
    /** @param array<string, mixed> $challenge */
    public function issue(array $challenge): void;

    /**
     * Consumes one attempt, including incorrect proofs. Implementations commit
     * failed attempts before returning and atomically consume successful codes.
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $id, string $codeHash, string $bindingHash, string $purpose, string $now): ?array;

    public function purge(string $before): int;
}
