<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use DateTimeZone;
use Providentia\SharedKernel\Application\Problem;
use Throwable;

final class HomePreferenceSyncEntityPolicy implements SyncEntityPolicy
{
    private const FIELDS = [
        'defaultLocale',
        'defaultCurrency',
        'defaultTimezone',
        'measurementSystem',
    ];

    public function entityType(): string
    {
        return 'home-preference';
    }

    public function validatePut(array $payload): void
    {
        if (array_diff(array_keys($payload), self::FIELDS) !== []) {
            throw new Problem(
                422,
                'Invalid operation',
                'The payload contains unknown or server-owned fields.',
            );
        }
        if ($payload === []) {
            throw new Problem(
                422,
                'Invalid operation',
                'home-preference requires at least one field.',
            );
        }

        $this->validateLocale($payload);
        $this->validateCurrency($payload);
        $this->validateTimezone($payload);
        $this->validateMeasurementSystem($payload);
    }

    /** @param array<string, mixed> $payload */
    private function validateLocale(array $payload): void
    {
        if (
            array_key_exists('defaultLocale', $payload)
            && (
                ! is_string($payload['defaultLocale'])
                || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $payload['defaultLocale']) !== 1
            )
        ) {
            throw new Problem(422, 'Invalid operation', 'defaultLocale is invalid.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateCurrency(array $payload): void
    {
        if (
            array_key_exists('defaultCurrency', $payload)
            && (
                ! is_string($payload['defaultCurrency'])
                || preg_match('/^[A-Z]{3}$/', $payload['defaultCurrency']) !== 1
            )
        ) {
            throw new Problem(422, 'Invalid operation', 'defaultCurrency is invalid.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateTimezone(array $payload): void
    {
        if (! array_key_exists('defaultTimezone', $payload)) {
            return;
        }
        if (! is_string($payload['defaultTimezone']) || mb_strlen($payload['defaultTimezone']) > 64) {
            throw new Problem(422, 'Invalid operation', 'defaultTimezone is invalid.');
        }
        try {
            new DateTimeZone($payload['defaultTimezone']);
        } catch (Throwable) {
            throw new Problem(422, 'Invalid operation', 'defaultTimezone is invalid.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateMeasurementSystem(array $payload): void
    {
        if (
            array_key_exists('measurementSystem', $payload)
            && (
                ! is_string($payload['measurementSystem'])
                || ! in_array($payload['measurementSystem'], ['metric', 'imperial'], true)
            )
        ) {
            throw new Problem(422, 'Invalid operation', 'measurementSystem is invalid.');
        }
    }
}
