<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Synchronization;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Providentia\SharedKernel\Application\Problem;
use Providentia\Synchronization\Application\HomePreferenceSyncEntityPolicy;
use Providentia\Synchronization\Application\PrivateNoteSyncEntityPolicy;
use Providentia\Synchronization\Application\SyncEntityPolicy;
use Providentia\Synchronization\Application\SyncEntityPolicyRegistry;

final class SyncEntityPolicyTest extends TestCase
{
    public function testPrivateNotePolicyAcceptsContractBoundaries(): void
    {
        $policy = new PrivateNoteSyncEntityPolicy();
        $policy->validatePut(['body' => ' ', 'title' => str_repeat('t', 120)]);

        self::assertSame('private-note', $policy->entityType());
    }

    public function testPrivateNotePolicyRejectsMissingBodyAndServerFields(): void
    {
        $policy = new PrivateNoteSyncEntityPolicy();

        try {
            $policy->validatePut(['title' => 'No body']);
            self::fail('A note without a body was accepted.');
        } catch (Problem $problem) {
            self::assertStringContainsString('body', $problem->getMessage());
        }

        $this->expectException(Problem::class);
        $this->expectExceptionMessage('server-owned');
        $policy->validatePut(['body' => 'valid', 'revision' => 9]);
    }

    public function testHomePreferencePolicyValidatesEverySupportedField(): void
    {
        $policy = new HomePreferenceSyncEntityPolicy();
        $policy->validatePut([
            'defaultLocale' => 'en-NA',
            'defaultCurrency' => 'NAD',
            'defaultTimezone' => 'Africa/Windhoek',
            'measurementSystem' => 'metric',
        ]);

        self::assertSame('home-preference', $policy->entityType());
    }

    public function testHomePreferencePolicyRejectsInvalidAndEmptyPatches(): void
    {
        $policy = new HomePreferenceSyncEntityPolicy();

        try {
            $policy->validatePut([]);
            self::fail('An empty preference patch was accepted.');
        } catch (Problem $problem) {
            self::assertStringContainsString('at least one field', $problem->getMessage());
        }

        foreach (
            [
                ['defaultLocale' => 'EN_na'],
                ['defaultCurrency' => 'nad'],
                ['defaultTimezone' => 'Not/A-Timezone'],
                ['measurementSystem' => 'customary'],
            ] as $payload
        ) {
            try {
                $policy->validatePut($payload);
                self::fail('An invalid preference field was accepted.');
            } catch (Problem $problem) {
                self::assertSame(422, $problem->status);
            }
        }
    }

    public function testRegistryRejectsMissingDuplicateAndUnknownPolicies(): void
    {
        try {
            new SyncEntityPolicyRegistry([]);
            self::fail('An empty registry was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('At least one', $error->getMessage());
        }

        $duplicate = new class implements SyncEntityPolicy {
            public function entityType(): string
            {
                return 'private-note';
            }

            public function validatePut(array $payload): void
            {
            }
        };

        try {
            new SyncEntityPolicyRegistry([new PrivateNoteSyncEntityPolicy(), $duplicate]);
            self::fail('Duplicate entity policies were accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('unique', $error->getMessage());
        }

        $registry = new SyncEntityPolicyRegistry([new PrivateNoteSyncEntityPolicy()]);
        $this->expectException(Problem::class);
        $registry->policyFor('unsupported-type');
    }
}
