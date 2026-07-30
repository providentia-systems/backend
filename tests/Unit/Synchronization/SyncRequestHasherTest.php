<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use PHPUnit\Framework\TestCase;
use Providentia\Synchronization\Application\SyncOperation;
use Providentia\Synchronization\Application\SyncRequestHasher;

final class SyncRequestHasherTest extends TestCase
{
    public function testEquivalentObjectKeyOrderProducesTheSameDigest(): void
    {
        $hasher = new SyncRequestHasher();
        $first = $this->operation(['title' => 'Freezer', 'body' => 'Inventory']);
        $second = $this->operation(['body' => 'Inventory', 'title' => 'Freezer']);

        self::assertSame($hasher->hash($first), $hasher->hash($second));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hasher->hash($first));
    }

    public function testMeaningfulRequestChangesProduceDifferentDigests(): void
    {
        $hasher = new SyncRequestHasher();

        self::assertNotSame(
            $hasher->hash($this->operation(['body' => 'one'])),
            $hasher->hash($this->operation(['body' => 'two'])),
        );
    }

    /** @param array<string, mixed> $payload */
    private function operation(array $payload): SyncOperation
    {
        return new SyncOperation(
            '01912345-6789-7abc-8def-0123456789ab',
            'private-note',
            '01912345-6789-7abc-9def-0123456789ab',
            'put',
            1,
            '2026-07-30T11:59:00+00:00',
            1,
            $payload,
        );
    }
}
