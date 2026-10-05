<?php

declare(strict_types=1);

namespace Providentia\Identity\Http;

use Providentia\SharedKernel\Http\JsonObjectMaps;

final class AccountProfileResponse
{
    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function serialize(array $profile): array
    {
        foreach (['accountAccess', 'administratorAccess'] as $field) {
            if (is_array($profile[$field] ?? null)) {
                $profile[$field] = JsonObjectMaps::serialize(
                    $profile[$field],
                    ['features', 'limits', 'rolePermissions'],
                );
            }
        }

        return $profile;
    }
}
