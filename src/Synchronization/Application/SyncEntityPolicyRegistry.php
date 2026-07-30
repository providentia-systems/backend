<?php

declare(strict_types=1);

namespace Providentia\Synchronization\Application;

use InvalidArgumentException;
use Providentia\SharedKernel\Application\Problem;

final class SyncEntityPolicyRegistry
{
    /** @var array<string, SyncEntityPolicy> */
    private array $policies = [];

    /** @param iterable<SyncEntityPolicy> $policies */
    public function __construct(iterable $policies)
    {
        foreach ($policies as $policy) {
            $entityType = $policy->entityType();
            if ($entityType === '' || isset($this->policies[$entityType])) {
                throw new InvalidArgumentException(
                    'Synchronization entity policies require unique, non-empty entity types.',
                );
            }
            $this->policies[$entityType] = $policy;
        }
        if ($this->policies === []) {
            throw new InvalidArgumentException('At least one synchronization entity policy is required.');
        }
    }

    public function policyFor(string $entityType): SyncEntityPolicy
    {
        return $this->policies[$entityType]
            ?? throw new Problem(
                422,
                'Invalid operation',
                'entityType is not enabled for synchronization.',
            );
    }
}
