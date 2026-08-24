<?php

declare(strict_types=1);

namespace Providentia\Home\Application;

/** Privacy-safe home and membership metadata for the operator control plane. */
interface OperatorHomeAccessReader
{
    /**
     * @param list<string> $userIds
     * @return array<string, list<array{homeId: string, name: string, membershipRole: string,
     *     membershipStatus: string}>>
     */
    public function operatorHomeAccess(array $userIds): array;
}
